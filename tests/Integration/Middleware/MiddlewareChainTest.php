<?php

declare(strict_types=1);

namespace Tests\Integration\Middleware;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Database\Migration\MigrationRunner;
use App\Core\Error\ErrorHandler;
use App\Core\Middleware\ApiPolicyMiddleware;
use App\Core\Middleware\AuthenticationMiddleware;
use App\Core\Middleware\AuthorizationMiddleware;
use App\Core\Middleware\SecurityMiddlewareRegistrar;
use App\Core\Policy\PolicyProvider;
use App\Core\Policy\SnapshotBuilder;
use App\Core\Repository\ApiKeyRepository;
use App\Core\Repository\ApiPolicyRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MiddlewareChainTest extends TestCase
{
    private PDO $pdo;
    private string $policyDir;
    private string $versionFile;
    private SnapshotBuilder $snapshotBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = new ConnectionFactory(new Config([
            'database' => [
                'type' => 'sqlite',
                'path' => ':memory:',
            ],
        ]));
        $this->pdo = $factory->create();

        $runner = new MigrationRunner($this->pdo, 'sqlite', dirname(__DIR__, 3) . '/migrations');
        $runner->run();

        $this->policyDir = sys_get_temp_dir() . '/php-api-platform-middleware-policy-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));

        $this->versionFile = sys_get_temp_dir() . '/php-api-platform-middleware-version-' . bin2hex(random_bytes(6));
        $this->snapshotBuilder = new SnapshotBuilder($this->pdo, $this->policyDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->versionFile)) {
            @unlink($this->versionFile);
        }

        $files = glob($this->policyDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        if (is_dir($this->policyDir)) {
            @rmdir($this->policyDir);
        }
    }

    public function testFullChainForPublicApi(): void
    {
        $this->createPolicy([
            'api_id' => 'test:public',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'public',
            'required_scopes' => [],
        ]);
        $this->buildSnapshot();

        $app = $this->createAppWithMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/test/public');

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testFullChainForPrivateApiWithValidKey(): void
    {
        $this->createPolicy([
            'api_id' => 'test:private',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['read'],
        ]);
        $this->createApiKey([
            'kid' => 'reader',
            'secret_hash' => hash_hmac('sha256', 'secret', 'pepper'),
            'scopes' => ['read', 'write'],
            'active' => 1,
        ]);
        $this->buildSnapshot();

        $app = $this->createAppWithMiddleware();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test/private')
            ->withHeader('X-API-Key', 'reader.secret');

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPrivateApiReturns401WithoutKey(): void
    {
        $this->createPolicy([
            'api_id' => 'test:private',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => [],
        ]);
        $this->buildSnapshot();

        $app = $this->createAppWithMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/test/private');

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('UNAUTHORIZED', $payload['error']['code']);
    }

    public function testPrivateApiReturns403WithInsufficientScopes(): void
    {
        $this->createPolicy([
            'api_id' => 'test:private',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['admin'],
        ]);
        $this->createApiKey([
            'kid' => 'reader',
            'secret_hash' => hash_hmac('sha256', 'secret', 'pepper'),
            'scopes' => ['read'],
            'active' => 1,
        ]);
        $this->buildSnapshot();

        $app = $this->createAppWithMiddleware();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test/private')
            ->withHeader('X-API-Key', 'reader.secret');

        $response = $app->handle($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('FORBIDDEN', $payload['error']['code']);
    }

    public function testDisabledApiReturns404(): void
    {
        $this->createPolicy([
            'api_id' => 'test:disabled',
            'plugin_id' => 'test-plugin',
            'enabled' => 0,
            'visibility' => 'public',
        ]);
        $this->buildSnapshot();

        $app = $this->createAppWithMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/test/disabled');

        $response = $app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ROUTE_NOT_FOUND', $payload['error']['code']);
    }

    private function createAppWithMiddleware(): \Slim\App
    {
        $app = AppFactory::create();

        $policyProvider = new PolicyProvider($this->policyDir, debounceMilliseconds: 0.0);
        $apiKeyProvider = new \App\Core\ApiKey\ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);

        $apiPolicyMiddleware = new ApiPolicyMiddleware($policyProvider);
        $authenticationMiddleware = new AuthenticationMiddleware($apiKeyProvider);
        $authorizationMiddleware = new AuthorizationMiddleware();

        SecurityMiddlewareRegistrar::register(
            $app,
            $apiPolicyMiddleware,
            $authenticationMiddleware,
            $authorizationMiddleware,
        );

        $app->addRoutingMiddleware();

        $app->get('/api/test/public', static function ($request, $response) {
            $response->getBody()->write('{"ok":true}');

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('test:public');

        $app->get('/api/test/private', static function ($request, $response) {
            $response->getBody()->write('{"ok":true}');

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('test:private');

        $app->get('/api/test/disabled', static function ($request, $response) {
            $response->getBody()->write('{"ok":true}');

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('test:disabled');

        $errorMiddleware = $app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

        return $app;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function createPolicy(array $policy): void
    {
        $repository = new ApiPolicyRepository($this->pdo);
        $repository->upsert($policy);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createApiKey(array $row): void
    {
        $repository = new ApiKeyRepository($this->pdo);
        $repository->create([
            'kid' => $row['kid'],
            'secret_hash' => $row['secret_hash'],
            'scopes' => $row['scopes'] ?? [],
            'active' => $row['active'] ?? 1,
            'description' => $row['description'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'last_used_at' => $row['last_used_at'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
        ]);
    }

    private function buildSnapshot(): void
    {
        $this->snapshotBuilder->build([]);
    }
}
