<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Core\Database\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseFile = sys_get_temp_dir() . '/php_api_platform_test_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->databaseFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        @unlink($this->databaseFile);

        parent::tearDown();
    }

    public function testRunSqliteCoreTablesMigration(): void
    {
        $runner = new MigrationRunner($this->pdo, 'sqlite', dirname(__DIR__, 3) . '/migrations');
        $result = $runner->run();

        self::assertContains('001_core_tables.sql', $result['executed']);

        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('api_policy', $tables);
        self::assertContains('api_key', $tables);
        self::assertContains('schema_version', $tables);
    }
}
