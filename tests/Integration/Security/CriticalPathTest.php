<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Config\Config;
use App\Core\Controller\HealthController;
use App\Core\Database\ConnectionFactory;
use App\Core\Database\Migration\MigrationRunner;
use App\Core\Error\ErrorHandler;
use App\Core\Logger\LoggerFactory;
use App\Core\Middleware\ApiPolicyMiddleware;
use App\Core\Middleware\AuthenticationMiddleware;
use App\Core\Middleware\AuthorizationMiddleware;
use App\Core\Middleware\SecurityMiddlewareRegistrar;
use App\Core\Middleware\TraceContextMiddleware;
use App\Core\Policy\PolicyProvider;
use App\Core\Policy\SnapshotBuilder;
use App\Core\Repository\ApiKeyRepository;
use App\Core\Repository\ApiPolicyRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CriticalPathTest extends TestCase
{
    private PDO $pdo;
    private \Slim\App $app;
    private string $policyDir;
    private string $versionFile;
    private SnapshotBuilder $snapshotBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['API_KEY_PEPPER'] = 'pepper';
        putenv('API_KEY_PEPPER=pepper');

        $factory = new ConnectionFactory(new Config([
            'database' => [
                'type' => 'sqlite',
                'path' => ':memory:',
            ],
        ]));
        $this->pdo = $factory->create();

        $runner = new MigrationRunner($this->pdo, 'sqlite', dirname(__DIR__, 3) . '/migrations');
        $runner->run();

        $this->policyDir = sys_get_temp_dir() . '/php-api-platform-critical-policy-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));

        $this->versionFile = sys_get_temp_dir() . '/php-api-platform-critical-version-' . bin2hex(random_bytes(6));

        $provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);
        $policyProvider = new PolicyProvider($this->policyDir, debounceMilliseconds: 0.0);

        $logger = (new LoggerFactory())->create('critical-path');
        self::assertInstanceOf(LoggerInterface::class, $logger);

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->add(new TraceContextMiddleware($logger));

        $apiPolicyMiddleware = new ApiPolicyMiddleware($policyProvider);
        $authenticationMiddleware = new AuthenticationMiddleware($provider);
        $authorizationMiddleware = new AuthorizationMiddleware();
        SecurityMiddlewareRegistrar::register($this->app, $apiPolicyMiddleware, $authenticationMiddleware, $authorizationMiddleware);
        $this->app->addRoutingMiddleware();

        $this->app->get('/health', new HealthController());
        $this->app->get('/api/public/test', static function ($request, $response) {
            $response->getBody()->write('{"status":"public-ok"}');

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('public:test:get');

        $this->app->get('/api/private/test', static function ($request, $response) {
            $response->getBody()->write('{"status":"private-ok"}');

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('private:test:get');

        $this->app->get('/api/disabled/test', static function ($request, $response) {
            $response->getBody()->write('{"status":"disabled"}');

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('disabled:test:get');

        $errorMiddleware = $this->app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

        $this->snapshotBuilder = new SnapshotBuilder($this->pdo, $this->policyDir);
    }

    protected function tearDown(): void
    {
        if (is_file($this->versionFile)) {
            @unlink($this->versionFile);
        }

        $files = glob($this->policyDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        if (is_dir($this->policyDir)) {
            rmdir($this->policyDir);
        }

        unset($_ENV['API_KEY_PEPPER']);
        putenv('API_KEY_PEPPER');

        parent::tearDown();
    }

    public function testPublicApiNoAuthRequired(): void
    {
        $this->createPolicy([
            'api_id' => 'public:test:get',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'public',
        ]);
        $this->buildSnapshot();

        $response = $this->request('GET', '/api/public/test');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPrivateApiReturns401WithoutKey(): void
    {
        $this->createPolicy([
            'api_id' => 'private:test:get',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['read'],
        ]);
        $this->buildSnapshot();

        $response = $this->request('GET', '/api/private/test');

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('API key', (string) ($body['error']['message'] ?? ''));
    }

    public function testPrivateApiReturns401WithInvalidKey(): void
    {
        $this->createPolicy([
            'api_id' => 'private:test:get',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['read'],
        ]);
        $this->buildSnapshot();

        $response = $this->request('GET', '/api/private/test', [], [
            'X-API-Key' => 'invalid.key',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testPrivateApiReturns403WithInsufficientScopes(): void
    {
        $this->createPolicy([
            'api_id' => 'private:test:get',
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

        $response = $this->request('GET', '/api/private/test', [], [
            'X-API-Key' => 'reader.secret',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPrivateApiReturns200WithValidKeyAndScopes(): void
    {
        $this->createPolicy([
            'api_id' => 'private:test:get',
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

        $response = $this->request('GET', '/api/private/test', [], [
            'X-API-Key' => 'reader.secret',
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testDisabledApiReturns404(): void
    {
        $this->createPolicy([
            'api_id' => 'disabled:test:get',
            'plugin_id' => 'test-plugin',
            'enabled' => 0,
            'visibility' => 'public',
        ]);
        $this->buildSnapshot();

        $response = $this->request('GET', '/api/disabled/test');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRevokedKeyReturns401(): void
    {
        $this->createPolicy([
            'api_id' => 'private:test:get',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['read'],
        ]);
        $this->createApiKey([
            'kid' => 'revoked',
            'secret_hash' => hash_hmac('sha256', 'secret', 'pepper'),
            'scopes' => ['read'],
            'active' => 0,
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);
        $this->buildSnapshot();

        $response = $this->request('GET', '/api/private/test', [], [
            'X-API-Key' => 'revoked.secret',
        ]);

        self::assertSame(401, $response->getStatusCode());
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

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, array $body = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== []) {
            $request->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        return $this->app->handle($request);
    }
}
