<?php

declare(strict_types=1);

namespace Tests\Integration\Audit;

use App\Core\ApiKey\ApiKeyGenerator;
use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Config\Config;
use App\Core\Controller\ApiKeyController;
use App\Core\Controller\ApiPolicyController;
use App\Core\Database\ConnectionFactory;
use App\Core\Database\Migration\MigrationRunner;
use App\Core\Error\ErrorHandler;
use App\Core\Policy\SnapshotBuilder;
use App\Core\Repository\ApiKeyRepository;
use App\Core\Repository\ApiPolicyRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AdminAuditLogTest extends TestCase
{
    private PDO $pdo;
    private \Slim\App $app;
    private string $auditFile;
    private string $policyDir;
    private string $versionFile;

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

        $this->policyDir = sys_get_temp_dir() . '/php-api-platform-audit-policy-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));

        $this->versionFile = sys_get_temp_dir() . '/php-api-platform-audit-version-' . bin2hex(random_bytes(6));
        $this->auditFile = sys_get_temp_dir() . '/php-api-platform-admin-audit-' . bin2hex(random_bytes(6)) . '.log';

        $_ENV['API_KEY_PEPPER'] = 'pepper';
        $_ENV['ADMIN_AUDIT_LOG_FILE'] = $this->auditFile;
        putenv('API_KEY_PEPPER=pepper');
        putenv('ADMIN_AUDIT_LOG_FILE=' . $this->auditFile);

        $apiKeyRepository = new ApiKeyRepository($this->pdo);
        $provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);
        $apiKeyController = new ApiKeyController(
            $apiKeyRepository,
            $provider,
            new ApiKeyGenerator(),
            $this->pdo,
            'pepper',
        );

        $policyRepository = new ApiPolicyRepository($this->pdo);
        $snapshotBuilder = new SnapshotBuilder($this->pdo, $this->policyDir);
        $apiPolicyController = new ApiPolicyController($policyRepository, $snapshotBuilder, $this->pdo);

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->addRoutingMiddleware();

        $this->app->post('/admin/api-keys', [$apiKeyController, 'create']);
        $this->app->delete('/admin/api-keys/{kid}', [$apiKeyController, 'revoke']);
        $this->app->post('/admin/api-policy', [$apiPolicyController, 'upsert']);

        $errorMiddleware = $this->app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());
    }

    protected function tearDown(): void
    {
        if (is_file($this->auditFile)) {
            @unlink($this->auditFile);
        }

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

        unset($_ENV['API_KEY_PEPPER'], $_ENV['ADMIN_AUDIT_LOG_FILE']);
        putenv('API_KEY_PEPPER');
        putenv('ADMIN_AUDIT_LOG_FILE');

        parent::tearDown();
    }

    public function testApiKeyCreateAndRevokeWriteFileAuditLogs(): void
    {
        $create = $this->request('POST', '/admin/api-keys', [
            'scopes' => ['read'],
            'description' => 'audit-create',
        ], [
            'X-Admin-User' => 'admin',
            'X-Forwarded-For' => '10.20.30.40',
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(201, $create->getStatusCode());

        $created = json_decode((string) $create->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $kid = (string) $created['data']['kid'];

        $revoke = $this->request('DELETE', '/admin/api-keys/' . $kid, [], [
            'X-Admin-User' => 'admin',
            'X-Forwarded-For' => '10.20.30.41',
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        self::assertSame(204, $revoke->getStatusCode());

        $logs = $this->readAuditLogLines();
        self::assertCount(2, $logs);

        self::assertSame('api_key.create', (string) ($logs[0]['action'] ?? ''));
        self::assertSame('10.20.30.40', (string) ($logs[0]['ip'] ?? ''));
        self::assertSame('api_key.revoke', (string) ($logs[1]['action'] ?? ''));
        self::assertSame('10.20.30.41', (string) ($logs[1]['ip'] ?? ''));
        self::assertSame($kid, (string) (($logs[1]['details']['kid'] ?? '')));
    }

    public function testPolicyUpsertWritesFileAuditLog(): void
    {
        $response = $this->request('POST', '/admin/api-policy', [
            'api_id' => 'test:audit:route',
            'plugin_id' => 'audit-plugin',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => ['admin'],
        ], [
            'X-Admin-User' => 'ops-admin',
            'X-Forwarded-For' => '192.168.1.8',
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(201, $response->getStatusCode());

        $logs = $this->readAuditLogLines();
        self::assertNotEmpty($logs);

        $last = $logs[array_key_last($logs)];
        self::assertSame('policy.upsert', (string) ($last['action'] ?? ''));
        self::assertSame('ops-admin', (string) ($last['actor'] ?? ''));
        self::assertSame('test:audit:route', (string) (($last['details']['api_id'] ?? '')));
        self::assertSame('192.168.1.8', (string) ($last['ip'] ?? ''));
    }

    public function testAuditLogFormatContainsRequiredFields(): void
    {
        $response = $this->request('POST', '/admin/api-keys', ['scopes' => []], [
            'X-Admin-User' => 'format-checker',
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertSame(201, $response->getStatusCode());

        $logs = $this->readAuditLogLines();
        self::assertNotEmpty($logs);

        $last = $logs[array_key_last($logs)];
        self::assertArrayHasKey('timestamp', $last);
        self::assertArrayHasKey('action', $last);
        self::assertArrayHasKey('actor', $last);
        self::assertArrayHasKey('ip', $last);
        self::assertArrayHasKey('details', $last);
        self::assertSame('127.0.0.1', (string) ($last['ip'] ?? ''));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @param array<string, mixed> $serverParams
     */
    private function request(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        array $serverParams = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, $serverParams);

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
     * @return list<array<string, mixed>>
     */
    private function readAuditLogLines(): array
    {
        if (!is_file($this->auditFile)) {
            return [];
        }

        $lines = file($this->auditFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        ));
    }
}
