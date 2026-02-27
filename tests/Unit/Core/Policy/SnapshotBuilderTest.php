<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Policy;

use App\Core\Policy\SnapshotBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

final class SnapshotBuilderTest extends TestCase
{
    private PDO $pdo;
    private string $policyDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE api_policy (
                api_id TEXT PRIMARY KEY,
                plugin_id TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                visibility TEXT NOT NULL DEFAULT 'private',
                required_scopes TEXT NOT NULL DEFAULT '[]',
                constraints TEXT NOT NULL DEFAULT '{}',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
            SQL
        );

        $this->policyDir = sys_get_temp_dir() . '/policy_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->policyDir);

        parent::tearDown();
    }

    public function testBuildGeneratesSnapshotAndVersionFiles(): void
    {
        $builder = new SnapshotBuilder($this->pdo, $this->policyDir);
        $builder->build([]);

        self::assertFileExists($this->policyDir . '/snapshot.json');
        self::assertFileExists($this->policyDir . '/version');
    }

    public function testMergePluginDefaults(): void
    {
        $builder = new SnapshotBuilder($this->pdo, $this->policyDir);
        $builder->build([
            [
                'apiId' => 'test:api:get',
                'pluginId' => 'plugin-a',
                'visibilityDefault' => 'public',
                'requiredScopesDefault' => ['read'],
            ],
        ]);

        $snapshot = json_decode(
            (string) file_get_contents($this->policyDir . '/snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('test:api:get', $snapshot);
        self::assertSame('plugin-a', $snapshot['test:api:get']['plugin_id']);
        self::assertSame('public', $snapshot['test:api:get']['visibility']);
        self::assertSame(['read'], $snapshot['test:api:get']['required_scopes']);
    }

    public function testDatabaseOverridesPluginDefaults(): void
    {
        $this->pdo->exec(
            "INSERT INTO api_policy (api_id, plugin_id, enabled, visibility, required_scopes, constraints) VALUES ('test:api:get', 'plugin-a', 0, 'private', '[\"admin\"]', '{\"rate_limit\":10}')"
        );

        $builder = new SnapshotBuilder($this->pdo, $this->policyDir);
        $builder->build([
            [
                'apiId' => 'test:api:get',
                'pluginId' => 'plugin-a',
                'visibilityDefault' => 'public',
                'requiredScopesDefault' => ['read'],
            ],
        ]);

        $snapshot = json_decode(
            (string) file_get_contents($this->policyDir . '/snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(0, $snapshot['test:api:get']['enabled']);
        self::assertSame('private', $snapshot['test:api:get']['visibility']);
        self::assertSame(['admin'], $snapshot['test:api:get']['required_scopes']);
        self::assertSame(['rate_limit' => 10], $snapshot['test:api:get']['constraints']);
    }

    public function testAtomicWriteLeavesNoTempFile(): void
    {
        $builder = new SnapshotBuilder($this->pdo, $this->policyDir);
        $builder->build([]);

        $tempSnapshotFiles = glob($this->policyDir . '/snapshot.json.tmp*') ?: [];
        $tempVersionFiles = glob($this->policyDir . '/version.tmp*') ?: [];

        self::assertSame([], $tempSnapshotFiles);
        self::assertSame([], $tempVersionFiles);
    }

    public function testVersionFileUpdatedOnEveryBuild(): void
    {
        $builder = new SnapshotBuilder($this->pdo, $this->policyDir);

        $builder->build([]);
        $firstVersion = trim((string) file_get_contents($this->policyDir . '/version'));

        usleep(2_000);
        $builder->build([]);
        $secondVersion = trim((string) file_get_contents($this->policyDir . '/version'));

        self::assertNotSame($firstVersion, $secondVersion);
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
}
