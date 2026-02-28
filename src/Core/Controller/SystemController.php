<?php

declare(strict_types=1);

namespace App\Core\Controller;

use App\Core\Plugin\PluginManager;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Throwable;

final class SystemController
{
    private readonly string $policyDirectory;
    private readonly string $pluginsDirectory;

    public function __construct(
        private readonly PDO $pdo,
        string $policyDirectory = 'var/policy',
        string $pluginsDirectory = 'plugins',
    ) {
        $this->policyDirectory = $this->resolvePolicyDirectory($policyDirectory);
        $this->pluginsDirectory = $this->resolvePluginsDirectory($pluginsDirectory);
    }

    public function info(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'data' => [
                'php_version' => PHP_VERSION,
                'database' => $this->databaseInfo(),
                'schema_version' => $this->schemaVersion(),
                'policy_version' => $this->policyVersion(),
                'plugins' => $this->pluginIds(),
                'api_count' => $this->countApiPolicies(),
                'api_key_count' => $this->countApiKeys(),
            ],
        ]);
    }

    public function health(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'policy' => $this->checkPolicySnapshot(),
            'apcu' => $this->checkApcu(),
        ];

        $isHealthy = !in_array(false, $checks, true);

        return $this->json(
            $response,
            [
                'data' => [
                    'status' => $isHealthy ? 'healthy' : 'unhealthy',
                    'checks' => $checks,
                ],
            ],
            $isHealthy ? 200 : 503,
        );
    }

    /**
     * @return array{type:string,version:?string}
     */
    private function databaseInfo(): array
    {
        $type = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        return [
            'type' => $type,
            'version' => $this->databaseVersion($type),
        ];
    }

    private function databaseVersion(string $driver): ?string
    {
        $sql = match ($driver) {
            'sqlite' => 'SELECT sqlite_version()',
            'mysql' => 'SELECT VERSION()',
            'pgsql' => 'SELECT current_setting(\'server_version\')',
            default => null,
        };

        if ($sql === null) {
            return null;
        }

        return $this->fetchScalar($sql);
    }

    private function schemaVersion(): ?string
    {
        return $this->fetchScalar('SELECT version FROM schema_version ORDER BY id DESC LIMIT 1');
    }

    private function policyVersion(): ?string
    {
        $versionFile = $this->policyDirectory . DIRECTORY_SEPARATOR . 'version';
        if (!is_file($versionFile) || !is_readable($versionFile)) {
            return null;
        }

        $value = file_get_contents($versionFile);
        if ($value === false) {
            return null;
        }

        $version = trim($value);

        return $version === '' ? null : $version;
    }

    /**
     * @return list<string>
     */
    private function pluginIds(): array
    {
        $pluginIds = $this->pluginIdsFromDirectory();
        if ($pluginIds !== []) {
            return $pluginIds;
        }

        return $this->pluginIdsFromDatabase();
    }

    /**
     * @return list<string>
     */
    private function pluginIdsFromDirectory(): array
    {
        if (!is_dir($this->pluginsDirectory)) {
            return [];
        }

        try {
            $manager = new PluginManager(new NullLogger());
            $plugins = $manager->loadPlugins($this->pluginsDirectory);
        } catch (Throwable) {
            return [];
        }

        if ($plugins === []) {
            return [];
        }

        $ids = array_values(array_filter(
            array_map(static fn (mixed $plugin): string => trim((string) ($plugin->getId() ?? '')), $plugins),
            static fn (string $pluginId): bool => $pluginId !== '',
        ));

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function pluginIdsFromDatabase(): array
    {
        try {
            $statement = $this->pdo->query(
                <<<'SQL'
                SELECT DISTINCT plugin_id
                FROM api_policy
                WHERE plugin_id IS NOT NULL AND TRIM(plugin_id) <> ''
                ORDER BY plugin_id ASC
                SQL,
            );
        } catch (Throwable) {
            return [];
        }

        if ($statement === false) {
            return [];
        }

        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        $plugins = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $rows),
            static fn (string $pluginId): bool => $pluginId !== '',
        ));

        return array_values(array_unique($plugins));
    }

    private function countApiPolicies(): int
    {
        return $this->countTableRows('api_policy');
    }

    private function countApiKeys(): int
    {
        return $this->countTableRows('api_key');
    }

    private function countTableRows(string $table): int
    {
        $count = $this->fetchScalar(sprintf('SELECT COUNT(*) FROM %s', $table));

        return $count === null ? 0 : max((int) $count, 0);
    }

    private function checkDatabase(): bool
    {
        return $this->fetchScalar('SELECT 1') !== null;
    }

    private function checkPolicySnapshot(): bool
    {
        $snapshotFile = $this->policyDirectory . DIRECTORY_SEPARATOR . 'snapshot.json';
        if (!is_file($snapshotFile) || !is_readable($snapshotFile)) {
            return false;
        }

        $content = file_get_contents($snapshotFile);
        if ($content === false) {
            return false;
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    private function checkApcu(): bool
    {
        if (function_exists('apcu_enabled')) {
            return apcu_enabled();
        }

        if (function_exists('apcu_store') && function_exists('apcu_fetch')) {
            return true;
        }

        return true;
    }

    private function fetchScalar(string $sql): ?string
    {
        try {
            $statement = $this->pdo->query($sql);
        } catch (Throwable) {
            return null;
        }

        if ($statement === false) {
            return null;
        }

        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolvePolicyDirectory(string $policyDirectory): string
    {
        if ($this->isAbsolutePath($policyDirectory)) {
            return rtrim($policyDirectory, DIRECTORY_SEPARATOR);
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($policyDirectory, DIRECTORY_SEPARATOR);
    }

    private function resolvePluginsDirectory(string $pluginsDirectory): string
    {
        if ($this->isAbsolutePath($pluginsDirectory)) {
            return rtrim($pluginsDirectory, DIRECTORY_SEPARATOR);
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($pluginsDirectory, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode = 200): ResponseInterface
    {
        $response->getBody()->write(
            (string) json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )
        );

        return $response
            ->withStatus($statusCode)
            ->withHeader('Content-Type', 'application/json');
    }
}
