<?php

declare(strict_types=1);

namespace App\Core\Policy;

use App\Core\Plugin\ApiDefinition;
use App\Core\Repository\ApiPolicyRepository;
use PDO;
use RuntimeException;

final class SnapshotBuilder
{
    private readonly string $policyDir;

    public function __construct(
        private readonly PDO $pdo,
        string $policyDir = 'var/policy',
    ) {
        $this->policyDir = $this->resolvePolicyDir($policyDir);
    }

    /**
     * @param list<ApiDefinition|array<string, mixed>> $pluginApis
     */
    public function build(array $pluginApis): void
    {
        $this->ensurePolicyDirectoryExists();

        $snapshot = $this->buildPluginDefaults($pluginApis);
        $repository = new ApiPolicyRepository($this->pdo);

        foreach ($repository->findAll() as $row) {
            $apiId = trim((string) ($row['api_id'] ?? ''));
            if ($apiId === '') {
                continue;
            }

            $current = $snapshot[$apiId] ?? [
                'api_id' => $apiId,
                'plugin_id' => null,
                'enabled' => 1,
                'visibility' => 'private',
                'required_scopes' => [],
                'constraints' => [],
                'source' => 'database',
            ];

            $snapshot[$apiId] = [
                'api_id' => $apiId,
                'plugin_id' => $this->nullableString($row['plugin_id'] ?? $current['plugin_id']),
                'enabled' => $this->normalizeBooleanInt($row['enabled'] ?? $current['enabled']),
                'visibility' => $this->normalizeVisibility($row['visibility'] ?? $current['visibility']),
                'required_scopes' => $this->normalizeScopes($row['required_scopes'] ?? $current['required_scopes']),
                'constraints' => $this->normalizeConstraints($row['constraints'] ?? $current['constraints']),
                'source' => 'database',
                'updated_at' => $this->nullableString($row['updated_at'] ?? null),
            ];
        }

        ksort($snapshot);

        $snapshotJson = json_encode(
            $snapshot,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if (!is_string($snapshotJson)) {
            throw new RuntimeException('Failed to build policy snapshot JSON.');
        }

        $snapshotJson .= PHP_EOL;

        $this->atomicWrite($this->snapshotFile(), $snapshotJson);
        $this->atomicWrite($this->versionFile(), sprintf('%.6F', microtime(true)) . PHP_EOL);
    }

    private function ensurePolicyDirectoryExists(): void
    {
        if (is_dir($this->policyDir)) {
            return;
        }

        if (!mkdir($this->policyDir, 0755, true) && !is_dir($this->policyDir)) {
            throw new RuntimeException(sprintf('Failed to create policy directory: %s', $this->policyDir));
        }
    }

    /**
     * @param list<ApiDefinition|array<string, mixed>> $pluginApis
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildPluginDefaults(array $pluginApis): array
    {
        $snapshot = [];

        foreach ($pluginApis as $apiDefinition) {
            if ($apiDefinition instanceof ApiDefinition) {
                $apiId = trim($apiDefinition->apiId);
                if ($apiId === '') {
                    continue;
                }

                $snapshot[$apiId] = [
                    'api_id' => $apiId,
                    'plugin_id' => null,
                    'enabled' => 1,
                    'visibility' => $this->normalizeVisibility($apiDefinition->visibilityDefault),
                    'required_scopes' => $this->normalizeScopes($apiDefinition->requiredScopesDefault),
                    'constraints' => [],
                    'source' => 'plugin',
                ];

                continue;
            }

            if (!is_array($apiDefinition)) {
                continue;
            }

            $apiId = trim((string) ($apiDefinition['apiId'] ?? $apiDefinition['api_id'] ?? ''));
            if ($apiId === '') {
                continue;
            }

            $snapshot[$apiId] = [
                'api_id' => $apiId,
                'plugin_id' => $this->nullableString($apiDefinition['pluginId'] ?? $apiDefinition['plugin_id'] ?? null),
                'enabled' => 1,
                'visibility' => $this->normalizeVisibility($apiDefinition['visibilityDefault'] ?? $apiDefinition['visibility'] ?? 'private'),
                'required_scopes' => $this->normalizeScopes($apiDefinition['requiredScopesDefault'] ?? $apiDefinition['required_scopes'] ?? []),
                'constraints' => $this->normalizeConstraints($apiDefinition['constraints'] ?? []),
                'source' => 'plugin',
            ];
        }

        return $snapshot;
    }

    private function atomicWrite(string $targetFile, string $content): void
    {
        $tmpFile = $targetFile . '.tmp';

        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write temp policy file: %s', $tmpFile));
        }

        if (!rename($tmpFile, $targetFile)) {
            @unlink($tmpFile);
            throw new RuntimeException(sprintf('Failed to replace policy file atomically: %s', $targetFile));
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeScopes(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $scopes = $decoded;
            } else {
                return [];
            }
        }

        if (!is_array($scopes)) {
            return [];
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '') {
                continue;
            }
            $normalized[] = $scope;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeConstraints(mixed $constraints): array
    {
        if (is_string($constraints)) {
            $decoded = json_decode($constraints, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return [];
        }

        if (!is_array($constraints)) {
            return [];
        }

        return $constraints;
    }

    private function normalizeBooleanInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return ((int) $value) === 1 ? 1 : 0;
    }

    private function normalizeVisibility(mixed $value): string
    {
        $visibility = strtolower(trim((string) $value));

        return in_array($visibility, ['public', 'private'], true) ? $visibility : 'private';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function resolvePolicyDir(string $policyDir): string
    {
        if ($this->isAbsolutePath($policyDir)) {
            return rtrim($policyDir, DIRECTORY_SEPARATOR);
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($policyDir, DIRECTORY_SEPARATOR);
    }

    private function snapshotFile(): string
    {
        return $this->policyDir . DIRECTORY_SEPARATOR . 'snapshot.json';
    }

    private function versionFile(): string
    {
        return $this->policyDir . DIRECTORY_SEPARATOR . 'version';
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }
}
