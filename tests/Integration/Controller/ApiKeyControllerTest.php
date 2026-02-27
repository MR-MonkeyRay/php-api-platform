<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Core\ApiKey\ApiKeyGenerator;
use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Config\Config;
use App\Core\Controller\ApiKeyController;
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

final class ApiKeyControllerTest extends TestCase
{
    private PDO $pdo;
    private \Slim\App $app;
    private string $policyDir;
    private string $versionFile;
    private string $adminFullKey;

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

        $this->policyDir = sys_get_temp_dir() . '/php-api-platform-apikey-policy-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));

        $this->versionFile = sys_get_temp_dir() . '/php-api-platform-apikey-version-' . bin2hex(random_bytes(6));

        $apiKeyRepository = new ApiKeyRepository($this->pdo);
        $provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);
        $controller = new ApiKeyController(
            $apiKeyRepository,
            $provider,
            new ApiKeyGenerator(),
            $this->pdo,
            'pepper',
        );

        $policyRepository = new ApiPolicyRepository($this->pdo);
        $snapshotBuilder = new SnapshotBuilder($this->pdo, $this->policyDir);
        $policyProvider = new PolicyProvider($this->policyDir, debounceMilliseconds: 0.0);

        $logger = (new LoggerFactory())->create('test');
        self::assertInstanceOf(LoggerInterface::class, $logger);

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->add(new TraceContextMiddleware($logger));

        $securityPolicyMiddleware = new ApiPolicyMiddleware($policyProvider);
        $authn = new AuthenticationMiddleware($provider);
        $authz = new AuthorizationMiddleware();
        SecurityMiddlewareRegistrar::register($this->app, $securityPolicyMiddleware, $authn, $authz);
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

        $this->app->post('/admin/api-keys', [$controller, 'create'])->setName('admin:api-keys:create');
        $this->app->get('/admin/api-keys', [$controller, 'list'])->setName('admin:api-keys:list');
        $this->app->get('/admin/api-keys/{kid}', [$controller, 'get'])->setName('admin:api-keys:get');
        $this->app->delete('/admin/api-keys/{kid}', [$controller, 'revoke'])->setName('admin:api-keys:revoke');

        $errorMiddleware = $this->app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

        $this->createPolicy([
            'api_id' => 'public:test:get',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'public',
        ], $policyRepository);
        $this->createPolicy([
            'api_id' => 'private:test:get',
            'plugin_id' => 'test-plugin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['read'],
        ], $policyRepository);
        $this->createPolicy([
            'api_id' => 'disabled:test:get',
            'plugin_id' => 'test-plugin',
            'enabled' => 0,
            'visibility' => 'public',
        ], $policyRepository);

        $this->createPolicy([
            'api_id' => 'admin:api-keys:create',
            'plugin_id' => 'platform-admin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['admin'],
        ], $policyRepository);
        $this->createPolicy([
            'api_id' => 'admin:api-keys:list',
            'plugin_id' => 'platform-admin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['admin'],
        ], $policyRepository);
        $this->createPolicy([
            'api_id' => 'admin:api-keys:get',
            'plugin_id' => 'platform-admin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['admin'],
        ], $policyRepository);
        $this->createPolicy([
            'api_id' => 'admin:api-keys:revoke',
            'plugin_id' => 'platform-admin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['admin'],
        ], $policyRepository);

        $this->createApiKey([
            'kid' => 'deadbeefdeadbeef',
            'secret_hash' => hash_hmac('sha256', 'admin-secret', 'pepper'),
            'active' => 1,
            'scopes' => ['admin'],
            'description' => 'Bootstrap admin key',
        ]);
        $this->adminFullKey = 'deadbeefdeadbeef.admin-secret';

        $snapshotBuilder->build([]);
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

    public function testCreateApiKey(): void
    {
        $response = $this->request('POST', '/admin/api-keys', [
            'scopes' => ['read', 'write'],
            'description' => 'Test key',
        ]);

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('kid', $body['data']);
        self::assertArrayHasKey('secret', $body['data']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['data']['kid']);
        self::assertSame(60, strlen($body['data']['kid'] . '.' . $body['data']['secret']));
    }

    public function testSecretReturnedOnlyOnce(): void
    {
        $response = $this->request('POST', '/admin/api-keys', ['scopes' => ['read']]);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $kid = $body['data']['kid'];

        $response = $this->request('GET', "/admin/api-keys/{$kid}");
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('secret', $body['data']);
        self::assertArrayNotHasKey('secret_hash', $body['data']);
    }

    public function testListApiKeys(): void
    {
        $this->createApiKey([
            'kid' => 'aaaaaaaaaaaaaaaa',
            'secret_hash' => hash_hmac('sha256', 's1', 'pepper'),
            'active' => 1,
            'scopes' => ['read'],
        ]);
        $this->createApiKey([
            'kid' => 'bbbbbbbbbbbbbbbb',
            'secret_hash' => hash_hmac('sha256', 's2', 'pepper'),
            'active' => 1,
            'scopes' => ['write'],
        ]);

        $response = $this->request('GET', '/admin/api-keys');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $body['data']);

        foreach ($body['data'] as $key) {
            self::assertArrayNotHasKey('secret', $key);
            self::assertArrayNotHasKey('secret_hash', $key);
        }
    }

    public function testRevokeApiKey(): void
    {
        $this->createApiKey([
            'kid' => 'cccccccccccccccc',
            'secret_hash' => hash_hmac('sha256', 'secret', 'pepper'),
            'active' => 1,
            'scopes' => ['read'],
        ]);

        $response = $this->request('DELETE', '/admin/api-keys/cccccccccccccccc');

        self::assertSame(204, $response->getStatusCode());

        $key = $this->getApiKey('cccccccccccccccc');
        self::assertSame(0, $key['active']);
        self::assertNotNull($key['revoked_at']);
    }

    public function testCreatedKeyCanAuthenticate(): void
    {
        $response = $this->request('POST', '/admin/api-keys', ['scopes' => ['read']]);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $fullKey = $body['data']['kid'] . '.' . $body['data']['secret'];

        $response = $this->request('GET', '/api/private/test', [], [
            'X-API-Key' => $fullKey,
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAdminApiKeysCreateReturns401WithoutApiKeyHeader(): void
    {
        $response = $this->request('POST', '/admin/api-keys', ['scopes' => ['read']], [
            'X-API-Key' => '',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAdminApiKeysCreateReturns403WithoutAdminScope(): void
    {
        $this->createApiKey([
            'kid' => 'readerreaderreader',
            'secret_hash' => hash_hmac('sha256', 'reader-secret', 'pepper'),
            'active' => 1,
            'scopes' => ['read'],
            'description' => 'reader key',
        ]);

        $response = $this->request('POST', '/admin/api-keys', ['scopes' => ['read']], [
            'X-API-Key' => 'readerreaderreader.reader-secret',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAuditLogCreatedOnCreateAndRevoke(): void
    {
        $create = $this->request('POST', '/admin/api-keys', [
            'scopes' => ['read'],
            'description' => 'audit test',
        ], [
            'X-Admin-User' => 'admin-user',
        ]);

        self::assertSame(201, $create->getStatusCode());
        $created = json_decode((string) $create->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $kid = $created['data']['kid'];

        $revoke = $this->request('DELETE', "/admin/api-keys/{$kid}", [], [
            'X-Admin-User' => 'admin-user',
        ]);
        self::assertSame(204, $revoke->getStatusCode());

        $logs = $this->getAuditLogs();
        self::assertNotEmpty($logs);

        self::assertSame('admin-user', $logs[0]['actor']);
        self::assertSame('revoke', $logs[0]['action']);
        self::assertSame('api_key', $logs[0]['target_type']);

        self::assertSame('admin-user', $logs[1]['actor']);
        self::assertSame('create', $logs[1]['action']);
        self::assertSame('api_key', $logs[1]['target_type']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);

        if (!array_key_exists('X-API-Key', $headers)) {
            $headers['X-API-Key'] = $this->adminFullKey;
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($data !== []) {
            $request->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function createPolicy(array $policy, ApiPolicyRepository $repository): void
    {
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

    /**
     * @return array<string, mixed>
     */
    private function getApiKey(string $kid): array
    {
        $repository = new ApiKeyRepository($this->pdo);
        $row = $repository->findByKid($kid);
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return list<array<string, string>>
     */
    private function getAuditLogs(): array
    {
        $statement = $this->pdo->query(
            'SELECT actor, action, target_type, target_id FROM audit_log ORDER BY id DESC'
        );
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(
            static fn (array $row): array => [
                'actor' => (string) ($row['actor'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'target_type' => (string) ($row['target_type'] ?? ''),
                'target_id' => (string) ($row['target_id'] ?? ''),
            ],
            is_array($rows) ? $rows : [],
        ));
    }
}
