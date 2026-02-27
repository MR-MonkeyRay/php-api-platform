<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Database\Migration\MigrationRunner;
use PDO;
use RuntimeException;

trait DatabaseTrait
{
    protected function createConnection(string $driver): PDO
    {
        $factory = new ConnectionFactory(new Config([
            'database' => $this->databaseConfigFor($driver),
        ]));

        return $factory->create();
    }

    /**
     * @return array{executed: list<string>, skipped: list<string>, dry_run: bool}
     */
    protected function runMigrations(PDO $pdo, string $driver): array
    {
        $runner = new MigrationRunner($pdo, $driver, $this->getMigrationsDir());

        return $runner->run();
    }

    protected function getMigrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    protected function assertTableExists(PDO $pdo, string $databaseType, string $table): void
    {
        $exists = match ($databaseType) {
            'sqlite' => $this->sqliteTableExists($pdo, $table),
            'mysql' => $this->mysqlTableExists($pdo, $table),
            'pgsql' => $this->pgsqlTableExists($pdo, $table),
            default => throw new RuntimeException(sprintf('Unsupported database driver: %s', $databaseType)),
        };

        self::assertTrue($exists, sprintf('Expected table "%s" to exist for driver "%s".', $table, $databaseType));
    }

    protected function ensureDriverEnabled(string $databaseType): void
    {
        $driver = strtolower(trim($databaseType));

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'mysql') {
            $enabled = strtolower((string) ($_ENV['TEST_DB_MYSQL_ENABLED'] ?? '0'));
            if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
                self::markTestSkipped('MySQL compatibility tests are disabled. Set TEST_DB_MYSQL_ENABLED=1 to enable.');
            }

            return;
        }

        if ($driver === 'pgsql') {
            $enabled = strtolower((string) ($_ENV['TEST_DB_PGSQL_ENABLED'] ?? '0'));
            if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
                self::markTestSkipped('PostgreSQL compatibility tests are disabled. Set TEST_DB_PGSQL_ENABLED=1 to enable.');
            }

            return;
        }

        self::markTestSkipped(sprintf('Unsupported database type: %s', $databaseType));
    }

    protected function resetCoreTables(PDO $pdo, string $databaseType): void
    {
        $driver = strtolower(trim($databaseType));

        match ($driver) {
            'sqlite' => $this->truncateSqlite($pdo),
            'mysql' => $this->truncateMysql($pdo),
            'pgsql' => $this->truncatePgsql($pdo),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConfigFor(string $driver): array
    {
        return match (strtolower(trim($driver))) {
            'sqlite' => [
                'type' => 'sqlite',
                'path' => getenv('TEST_DB_SQLITE_PATH') ?: ':memory:',
            ],
            'mysql' => [
                'type' => 'mysql',
                'host' => getenv('TEST_DB_MYSQL_HOST') ?: 'mysql',
                'port' => (int) (getenv('TEST_DB_MYSQL_PORT') ?: 3306),
                'name' => getenv('TEST_DB_MYSQL_NAME') ?: 'app_test',
                'user' => getenv('TEST_DB_MYSQL_USER') ?: 'app',
                'password' => getenv('TEST_DB_MYSQL_PASSWORD') ?: 'app',
                'charset' => getenv('TEST_DB_MYSQL_CHARSET') ?: 'utf8mb4',
            ],
            'pgsql' => [
                'type' => 'pgsql',
                'host' => getenv('TEST_DB_PGSQL_HOST') ?: 'pgsql',
                'port' => (int) (getenv('TEST_DB_PGSQL_PORT') ?: 5432),
                'name' => getenv('TEST_DB_PGSQL_NAME') ?: 'app_test',
                'user' => getenv('TEST_DB_PGSQL_USER') ?: 'app',
                'password' => getenv('TEST_DB_PGSQL_PASSWORD') ?: 'app',
            ],
            default => throw new RuntimeException(sprintf('Unsupported database driver: %s', $driver)),
        };
    }

    private function sqliteTableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $statement->execute(['name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function mysqlTableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name LIMIT 1'
        );
        $statement->execute(['name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function pgsqlTableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT to_regclass(:table_name)');
        $statement->execute(['table_name' => 'public.' . $table]);

        return $statement->fetchColumn() !== null;
    }

    private function truncateSqlite(PDO $pdo): void
    {
        $pdo->exec('DELETE FROM api_policy');
        $pdo->exec('DELETE FROM api_key');
    }

    private function truncateMysql(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE api_policy');
        $pdo->exec('TRUNCATE TABLE api_key');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function truncatePgsql(PDO $pdo): void
    {
        $pdo->exec('TRUNCATE TABLE api_policy, api_key');
    }
}
