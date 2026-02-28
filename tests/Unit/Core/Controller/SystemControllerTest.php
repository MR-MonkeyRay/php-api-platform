<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Controller;

use App\Core\Controller\SystemController;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SystemControllerTest extends TestCase
{
    private PDO $pdo;
    private string $policyDir;
    private string $pluginsDir;
    private SystemController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE schema_version (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL
            )
            SQL
        );
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE api_policy (
                api_id TEXT PRIMARY KEY,
                plugin_id TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                visibility TEXT NOT NULL DEFAULT 'private',
                required_scopes TEXT NOT NULL DEFAULT '[]',
                constraints TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL
        );
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE api_key (
                kid TEXT PRIMARY KEY,
                secret_hash TEXT NOT NULL,
                scopes TEXT NOT NULL DEFAULT '[]',
                active INTEGER NOT NULL DEFAULT 1,
                description TEXT,
                expires_at TEXT,
                last_used_at TEXT,
                revoked_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL
        );

        $this->pdo->exec("INSERT INTO schema_version (version) VALUES ('001_core_tables.sql')");
        $this->pdo->exec(
            "INSERT INTO api_policy (api_id, plugin_id, enabled, visibility, required_scopes, constraints) VALUES "
            . "('alpha:ping:get', 'alpha', 1, 'public', '[]', '{}'),"
            . "('beta:ping:get', 'beta', 1, 'private', '[\"read\"]', '{}'),"
            . "('beta:status:get', 'beta', 1, 'private', '[]', '{}')"
        );
        $this->pdo->exec(
            "INSERT INTO api_key (kid, secret_hash, scopes, active) VALUES "
            . "('aaaaaaaaaaaaaaaa', 'hash-a', '[\"admin\"]', 1),"
            . "('bbbbbbbbbbbbbbbb', 'hash-b', '[\"read\"]', 1)"
        );

        $this->policyDir = sys_get_temp_dir() . '/php-api-platform-system-controller-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));

        $this->pluginsDir = sys_get_temp_dir() . '/php-api-platform-system-controller-plugins-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->pluginsDir, 0755, true) || is_dir($this->pluginsDir));

        file_put_contents($this->policyDir . '/version', "1709012345\n");
        file_put_contents(
            $this->policyDir . '/snapshot.json',
            (string) json_encode([
                'alpha:ping:get' => ['enabled' => 1],
                'beta:ping:get' => ['enabled' => 1],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        $this->createPlugin(
            $this->pluginsDir,
            'alpha-plugin',
            [
                'id' => 'alpha',
                'name' => 'Alpha Plugin',
                'version' => '1.0.0',
                'mainClass' => 'AlphaPlugin',
            ],
            <<<'PHP'
            <?php
            use App\Core\Plugin\PluginInterface;
            use Slim\App;

            final class AlphaPlugin implements PluginInterface
            {
                public function getId(): string { return 'alpha'; }
                public function getName(): string { return 'Alpha Plugin'; }
                public function getVersion(): string { return '1.0.0'; }
                public function routes(App $app): void {}
                public function apis(): array { return []; }
            }
            PHP
        );

        $this->createPlugin(
            $this->pluginsDir,
            'beta-plugin',
            [
                'id' => 'beta',
                'name' => 'Beta Plugin',
                'version' => '1.0.0',
                'mainClass' => 'BetaPlugin',
            ],
            <<<'PHP'
            <?php
            use App\Core\Plugin\PluginInterface;
            use Slim\App;

            final class BetaPlugin implements PluginInterface
            {
                public function getId(): string { return 'beta'; }
                public function getName(): string { return 'Beta Plugin'; }
                public function getVersion(): string { return '1.0.0'; }
                public function routes(App $app): void {}
                public function apis(): array { return []; }
            }
            PHP
        );

        $this->controller = new SystemController($this->pdo, $this->policyDir, $this->pluginsDir);
    }

    protected function tearDown(): void
    {
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

        $this->deleteDirectory($this->pluginsDir);

        parent::tearDown();
    }

    public function testInfoReturnsRequiredSystemFields(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/system/info');
        $response = $this->controller->info($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);

        self::assertArrayHasKey('php_version', $body['data']);
        self::assertArrayHasKey('database', $body['data']);
        self::assertArrayHasKey('schema_version', $body['data']);
        self::assertArrayHasKey('policy_version', $body['data']);
        self::assertArrayHasKey('plugins', $body['data']);
        self::assertArrayHasKey('api_count', $body['data']);
        self::assertArrayHasKey('api_key_count', $body['data']);

        self::assertSame(PHP_VERSION, $body['data']['php_version']);
        self::assertSame('sqlite', $body['data']['database']['type']);
        self::assertNotEmpty((string) ($body['data']['database']['version'] ?? ''));
        self::assertSame('001_core_tables.sql', $body['data']['schema_version']);
        self::assertSame('1709012345', $body['data']['policy_version']);
        self::assertSame(['alpha', 'beta'], $body['data']['plugins']);
        self::assertSame(3, $body['data']['api_count']);
        self::assertSame(2, $body['data']['api_key_count']);
    }

    public function testHealthReturnsHealthyWhenAllChecksPass(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/system/health');
        $response = $this->controller->health($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('healthy', $body['data']['status']);
        self::assertTrue($body['data']['checks']['database']);
        self::assertTrue($body['data']['checks']['policy']);
        self::assertArrayHasKey('apcu', $body['data']['checks']);
        self::assertIsBool($body['data']['checks']['apcu']);
    }

    public function testHealthReturnsUnhealthyWhenPolicySnapshotMissing(): void
    {
        @unlink($this->policyDir . '/snapshot.json');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/system/health');
        $response = $this->controller->health($request, (new ResponseFactory())->createResponse());

        self::assertSame(503, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('unhealthy', $body['data']['status']);
        self::assertTrue($body['data']['checks']['database']);
        self::assertFalse($body['data']['checks']['policy']);
    }

    public function testHealthReturnsUnhealthyWhenDatabaseCheckFails(): void
    {
        $brokenPdo = new class () extends PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
            }

            public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): \PDOStatement|false
            {
                if (str_contains($query, 'SELECT 1')) {
                    throw new \PDOException('simulated database failure');
                }

                return parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };

        $controller = new SystemController($brokenPdo, $this->policyDir);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/system/health');
        $response = $controller->health($request, (new ResponseFactory())->createResponse());

        self::assertSame(503, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('unhealthy', $body['data']['status']);
        self::assertFalse($body['data']['checks']['database']);
    }

    public function testInfoFallsBackToDatabasePluginsWhenPluginDirectoryMissing(): void
    {
        $controller = new SystemController($this->pdo, $this->policyDir, $this->pluginsDir . '/missing');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/system/info');
        $response = $controller->info($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['alpha', 'beta'], $body['data']['plugins']);
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function createPlugin(string $pluginsRoot, string $directoryName, array $metadata, string $bootstrap): void
    {
        $dir = $pluginsRoot . '/' . $directoryName;
        self::assertTrue(mkdir($dir, 0755, true) || is_dir($dir));

        file_put_contents(
            $dir . '/plugin.json',
            (string) json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        file_put_contents($dir . '/bootstrap.php', $bootstrap);
    }
}
