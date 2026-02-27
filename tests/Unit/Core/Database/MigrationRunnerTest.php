<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Database;

use App\Core\Database\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private string $migrationsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->migrationsRoot = sys_get_temp_dir() . '/migrations_' . bin2hex(random_bytes(6));
        mkdir($this->migrationsRoot . '/sqlite', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->migrationsRoot);

        parent::tearDown();
    }

    public function testRunCreatesSchemaVersionAndExecutesMigrations(): void
    {
        file_put_contents(
            $this->migrationsRoot . '/sqlite/001_create_table.sql',
            'CREATE TABLE sample (id INTEGER PRIMARY KEY, name TEXT);'
        );

        $runner = new MigrationRunner($this->pdo, 'sqlite', $this->migrationsRoot);
        $result = $runner->run();

        self::assertSame(['001_create_table.sql'], $result['executed']);
        self::assertSame([], $result['skipped']);

        $table = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_version'")
            ->fetchColumn();

        self::assertSame('schema_version', $table);
    }

    public function testRunIsIdempotent(): void
    {
        file_put_contents(
            $this->migrationsRoot . '/sqlite/001_once.sql',
            'CREATE TABLE sample_once (id INTEGER PRIMARY KEY);'
        );

        $runner = new MigrationRunner($this->pdo, 'sqlite', $this->migrationsRoot);
        $runner->run();
        $secondRun = $runner->run();

        self::assertSame([], $secondRun['executed']);
        self::assertSame(['001_once.sql'], $secondRun['skipped']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testDryRunDoesNotApplyMigrations(): void
    {
        file_put_contents(
            $this->migrationsRoot . '/sqlite/001_dry_run.sql',
            'CREATE TABLE dry_run_table (id INTEGER PRIMARY KEY);'
        );

        $runner = new MigrationRunner($this->pdo, 'sqlite', $this->migrationsRoot);
        $result = $runner->run(true);

        self::assertTrue($result['dry_run']);
        self::assertSame(['001_dry_run.sql'], $result['executed']);

        $table = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='dry_run_table'")
            ->fetchColumn();

        self::assertFalse($table);
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
