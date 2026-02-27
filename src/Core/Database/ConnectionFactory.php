<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config\Config;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class ConnectionFactory
{
    private ?PDO $connection = null;

    /**
     * @var array<string, mixed>
     */
    private array $databaseConfig;

    public function __construct(array|Config $config)
    {
        $this->databaseConfig = $this->extractDatabaseConfig($config);
    }

    public function create(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $driver = $this->normalizeDriver((string) ($this->databaseConfig['type'] ?? 'sqlite'));

        [$dsn, $username, $password] = match ($driver) {
            'sqlite' => [$this->buildSqliteDsn(), null, null],
            'mysql' => [
                $this->buildMysqlDsn(),
                $this->getString('user', $this->getString('username', '')),
                $this->getString('password', ''),
            ],
            'pgsql' => [
                $this->buildPgsqlDsn(),
                $this->getString('user', $this->getString('username', '')),
                $this->getString('password', ''),
            ],
        };

        try {
            $this->connection = new PDO($dsn, $username, $password, $this->buildPdoOptions());
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Failed to connect to %s database: %s', $driver, $exception->getMessage()),
                previous: $exception,
            );
        }

        return $this->connection;
    }

    private function buildSqliteDsn(): string
    {
        $path = $this->getString('path', $this->getString('database', 'var/database/app.sqlite'));

        if ($path === ':memory:') {
            return 'sqlite::memory:';
        }

        if (!$this->isAbsolutePath($path)) {
            $path = $this->projectRoot() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create SQLite directory: %s', $directory));
        }

        return 'sqlite:' . $path;
    }

    private function buildMysqlDsn(): string
    {
        $host = $this->getString('host', '127.0.0.1');
        $port = (int) ($this->databaseConfig['port'] ?? 3306);
        $database = $this->getRequiredString('name', $this->getString('database', ''));
        $charset = $this->getString('charset', 'utf8mb4');

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset,
        );
    }

    private function buildPgsqlDsn(): string
    {
        $host = $this->getString('host', '127.0.0.1');
        $port = (int) ($this->databaseConfig['port'] ?? 5432);
        $database = $this->getRequiredString('name', $this->getString('database', ''));

        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database,
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function buildPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    /**
     * @param array<string, mixed>|Config $config
     *
     * @return array<string, mixed>
     */
    private function extractDatabaseConfig(array|Config $config): array
    {
        if (is_array($config)) {
            if (isset($config['database']) && is_array($config['database'])) {
                return $config['database'];
            }

            return $config;
        }

        $databaseConfig = $config->get('database', []);

        return is_array($databaseConfig) ? $databaseConfig : [];
    }

    private function normalizeDriver(string $driver): string
    {
        $normalized = strtolower(trim($driver));

        return match ($normalized) {
            'sqlite', 'mysql', 'pgsql' => $normalized,
            'postgres', 'postgresql' => 'pgsql',
            default => throw new InvalidArgumentException(sprintf('Unsupported database type: %s', $driver)),
        };
    }

    private function getString(string $key, string $default): string
    {
        $value = $this->databaseConfig[$key] ?? $default;

        if (is_array($value) || is_object($value)) {
            throw new InvalidArgumentException(sprintf('Database config "%s" must be a scalar value.', $key));
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function getRequiredString(string $key, string $fallback): string
    {
        $value = trim($this->getString($key, $fallback));

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Database config "%s" is required.', $key));
        }

        return $value;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
