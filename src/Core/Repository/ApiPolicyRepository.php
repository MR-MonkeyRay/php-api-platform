<?php

declare(strict_types=1);

namespace App\Core\Repository;

use PDO;
use RuntimeException;

final class ApiPolicyRepository implements RepositoryInterface
{
    /**
     * @var list<string>
     */
    private const JSON_FIELDS = ['required_scopes', 'constraints'];

    private readonly string $driver;
    private readonly string $quotedConstraints;
    private readonly string $upsertSql;

    public function __construct(
        private readonly PDO $pdo,
    ) {
        $this->driver = $this->resolveDriver($pdo);
        $this->quotedConstraints = $this->quoteIdentifier('constraints');
        $this->upsertSql = $this->buildUpsertSql();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByApiId(string $apiId): ?array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT api_id, plugin_id, enabled, visibility, required_scopes, %s AS constraints, created_at, updated_at
             FROM api_policy
             WHERE api_id = :api_id
             LIMIT 1',
            $this->quotedConstraints,
        ));
        $statement->execute(['api_id' => $apiId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->deserializeRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByPluginId(string $pluginId): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT api_id, plugin_id, enabled, visibility, required_scopes, %s AS constraints, created_at, updated_at
             FROM api_policy
             WHERE plugin_id = :plugin_id
             ORDER BY api_id ASC',
            $this->quotedConstraints,
        ));
        $statement->execute(['plugin_id' => $pluginId]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            fn (array $row): array => $this->deserializeRow($row),
            $rows,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $statement = $this->pdo->query(sprintf(
            'SELECT api_id, plugin_id, enabled, visibility, required_scopes, %s AS constraints, created_at, updated_at
             FROM api_policy
             ORDER BY api_id ASC',
            $this->quotedConstraints,
        ));
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            fn (array $row): array => $this->deserializeRow($row),
            $rows,
        ));
    }

    public function upsert(array $data): bool
    {
        $apiId = trim((string) ($data['api_id'] ?? ''));
        if ($apiId === '') {
            throw new RuntimeException('api_id is required for ApiPolicy upsert.');
        }

        $existing = $this->findByApiId($apiId);
        $payload = $this->buildPolicyPayload($existing, $data);

        $statement = $this->pdo->prepare($this->upsertSql);

        return $statement->execute($payload);
    }

    public function delete(string $apiId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM api_policy WHERE api_id = :api_id');
        $statement->execute(['api_id' => $apiId]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function buildPolicyPayload(?array $existing, array $input): array
    {
        $pluginId = trim((string) ($input['plugin_id'] ?? $existing['plugin_id'] ?? ''));
        if ($pluginId === '') {
            throw new RuntimeException('plugin_id is required for ApiPolicy upsert.');
        }

        return [
            'api_id' => (string) ($input['api_id'] ?? $existing['api_id'] ?? ''),
            'plugin_id' => $pluginId,
            'enabled' => $this->normalizeBooleanInt($input['enabled'] ?? $existing['enabled'] ?? 1),
            'visibility' => (string) ($input['visibility'] ?? $existing['visibility'] ?? 'private'),
            'required_scopes' => $this->encodeJsonField(
                $input['required_scopes'] ?? $existing['required_scopes'] ?? []
            ),
            'constraints' => $this->encodeJsonField(
                $input['constraints'] ?? $existing['constraints'] ?? new \stdClass()
            ),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function buildUpsertSql(): string
    {
        $constraintsColumn = $this->quotedConstraints;

        if ($this->driver === 'mysql') {
            return sprintf(
                <<<'SQL'
                INSERT INTO api_policy (
                    api_id,
                    plugin_id,
                    enabled,
                    visibility,
                    required_scopes,
                    %s,
                    updated_at
                ) VALUES (
                    :api_id,
                    :plugin_id,
                    :enabled,
                    :visibility,
                    :required_scopes,
                    :constraints,
                    :updated_at
                )
                ON DUPLICATE KEY UPDATE
                    plugin_id = VALUES(plugin_id),
                    enabled = VALUES(enabled),
                    visibility = VALUES(visibility),
                    required_scopes = VALUES(required_scopes),
                    %s = VALUES(%s),
                    updated_at = VALUES(updated_at)
                SQL,
                $constraintsColumn,
                $constraintsColumn,
                $constraintsColumn,
            );
        }

        return sprintf(
            <<<'SQL'
            INSERT INTO api_policy (
                api_id,
                plugin_id,
                enabled,
                visibility,
                required_scopes,
                %s,
                updated_at
            ) VALUES (
                :api_id,
                :plugin_id,
                :enabled,
                :visibility,
                :required_scopes,
                :constraints,
                :updated_at
            )
            ON CONFLICT(api_id)
            DO UPDATE SET
                plugin_id = EXCLUDED.plugin_id,
                enabled = EXCLUDED.enabled,
                visibility = EXCLUDED.visibility,
                required_scopes = EXCLUDED.required_scopes,
                %s = EXCLUDED.%s,
                updated_at = EXCLUDED.updated_at
            SQL,
            $constraintsColumn,
            $constraintsColumn,
            $constraintsColumn,
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function deserializeRow(array $row): array
    {
        foreach (self::JSON_FIELDS as $field) {
            $row[$field] = $this->decodeJsonField($row[$field] ?? null);
        }

        if (isset($row['enabled'])) {
            $row['enabled'] = (int) $row['enabled'];
        }

        return $row;
    }

    private function normalizeBooleanInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return ((int) $value) === 1 ? 1 : 0;
    }

    private function encodeJsonField(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                throw new RuntimeException('Invalid JSON string provided for API policy field.');
            }
        }

        try {
            return (string) json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('Failed to encode API policy JSON field.', previous: $exception);
        }
    }

    private function decodeJsonField(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function resolveDriver(PDO $pdo): string
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        return match ($driver) {
            'sqlite', 'mysql', 'pgsql' => $driver,
            default => throw new RuntimeException(sprintf('Unsupported PDO driver: %s', $driver)),
        };
    }

    private function quoteIdentifier(string $identifier): string
    {
        $escaped = str_replace('"', '""', $identifier);

        if ($this->driver === 'mysql') {
            return sprintf('`%s`', str_replace('`', '``', $escaped));
        }

        return sprintf('"%s"', $escaped);
    }
}
