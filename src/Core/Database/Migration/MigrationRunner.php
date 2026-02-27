<?php

declare(strict_types=1);

namespace App\Core\Database\Migration;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private string $driver;

    public function __construct(
        private readonly PDO $pdo,
        string $driver,
        private readonly string $migrationsRoot = 'migrations',
    ) {
        $this->driver = $this->normalizeDriver($driver);
    }

    /**
     * @return array{executed: list<string>, skipped: list<string>, dry_run: bool}
     */
    public function run(bool $dryRun = false): array
    {
        if (!$dryRun) {
            $this->ensureSchemaVersionTable();
        }

        $appliedVersions = $this->getAppliedVersions();
        $migrations = $this->getMigrationFiles();

        $result = [
            'executed' => [],
            'skipped' => [],
            'dry_run' => $dryRun,
        ];

        foreach ($migrations as $file) {
            $version = basename($file);
            if (in_array($version, $appliedVersions, true)) {
                $result['skipped'][] = $version;
                continue;
            }

            if ($dryRun) {
                $result['executed'][] = $version;
                continue;
            }

            $this->applyMigration($file, $version);
            $result['executed'][] = $version;
            $appliedVersions[] = $version;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getMigrationFiles(): array
    {
        $path = $this->getDriverMigrationPath();
        if (!is_dir($path)) {
            throw new RuntimeException(sprintf('Migration directory does not exist: %s', $path));
        }

        $files = glob($path . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_NATURAL);

        /** @var list<string> $files */
        return $files;
    }

    /**
     * @return list<string>
     */
    private function getAppliedVersions(): array
    {
        if (!$this->schemaVersionTableExists()) {
            return [];
        }

        $statement = $this->pdo->query('SELECT version FROM schema_version ORDER BY id ASC');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);

        /** @var list<string|int|float|null> $rows */
        return array_values(
            array_filter(
                array_map(static fn (string|int|float|null $version): string => (string) $version, $rows),
                static fn (string $version): bool => $version !== '',
            ),
        );
    }

    private function applyMigration(string $file, string $version): void
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException(sprintf('Failed to read migration file: %s', $file));
        }

        $sql = trim($content);

        try {
            $this->pdo->beginTransaction();

            if ($sql !== '') {
                $this->pdo->exec($sql);
            }

            $statement = $this->pdo->prepare('INSERT INTO schema_version (version) VALUES (:version)');
            $statement->execute(['version' => $version]);

            $this->pdo->commit();
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException(
                sprintf('Migration failed (%s): %s', $version, $throwable->getMessage()),
                previous: $throwable,
            );
        }
    }

    private function ensureSchemaVersionTable(): void
    {
        $this->pdo->exec($this->schemaVersionCreateSql());
    }

    private function schemaVersionTableExists(): bool
    {
        return match ($this->driver) {
            'sqlite' => $this->tableExistsSqlite(),
            'mysql' => $this->tableExistsMysql(),
            'pgsql' => $this->tableExistsPgsql(),
        };
    }

    private function tableExistsSqlite(): bool
    {
        $statement = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='schema_version' LIMIT 1",
        );

        return $statement !== false && $statement->fetchColumn() !== false;
    }

    private function tableExistsMysql(): bool
    {
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_version' LIMIT 1",
        );

        return $statement !== false && $statement->fetchColumn() !== false;
    }

    private function tableExistsPgsql(): bool
    {
        $statement = $this->pdo->query("SELECT to_regclass('public.schema_version')");

        return $statement !== false && $statement->fetchColumn() !== null;
    }

    private function schemaVersionCreateSql(): string
    {
        return match ($this->driver) {
            'sqlite' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS schema_version (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    version VARCHAR(255) NOT NULL UNIQUE,
                    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                SQL,
            'mysql' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS schema_version (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    version VARCHAR(255) NOT NULL UNIQUE,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                SQL,
            'pgsql' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS schema_version (
                    id BIGSERIAL PRIMARY KEY,
                    version VARCHAR(255) NOT NULL UNIQUE,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                SQL,
        };
    }

    private function getDriverMigrationPath(): string
    {
        $root = rtrim($this->migrationsRoot, DIRECTORY_SEPARATOR);
        if (!$this->isAbsolutePath($root)) {
            $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . $root;
        }

        return $root . DIRECTORY_SEPARATOR . $this->driver;
    }

    private function normalizeDriver(string $driver): string
    {
        $normalized = strtolower(trim($driver));

        return match ($normalized) {
            'sqlite', 'mysql', 'pgsql' => $normalized,
            'postgres', 'postgresql' => 'pgsql',
            default => throw new RuntimeException(sprintf('Unsupported migration driver: %s', $driver)),
        };
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }
}
