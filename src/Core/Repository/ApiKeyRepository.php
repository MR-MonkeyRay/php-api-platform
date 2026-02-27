<?php

declare(strict_types=1);

namespace App\Core\Repository;

use PDO;
use RuntimeException;

final class ApiKeyRepository implements RepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKid(string $kid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM api_key WHERE kid = :kid LIMIT 1');
        $statement->execute(['kid' => $kid]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->deserializeRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByKid(string $kid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM api_key WHERE kid = :kid AND active = :active LIMIT 1');
        $statement->execute([
            'kid' => $kid,
            'active' => 1,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->deserializeRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $statement = $this->pdo->query('SELECT * FROM api_key ORDER BY kid ASC');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            fn (array $row): array => $this->deserializeRow($row),
            $rows,
        ));
    }

    public function create(array $data): bool
    {
        $kid = trim((string) ($data['kid'] ?? ''));
        $secretHash = trim((string) ($data['secret_hash'] ?? ''));

        if ($kid === '') {
            throw new RuntimeException('kid is required for ApiKey create.');
        }

        if ($secretHash === '') {
            throw new RuntimeException('secret_hash is required for ApiKey create.');
        }

        $payload = [
            'kid' => $kid,
            'secret_hash' => $secretHash,
            'scopes' => $this->encodeJsonField($data['scopes'] ?? []),
            'active' => $this->normalizeBooleanInt($data['active'] ?? 1),
            'description' => $this->nullableString($data['description'] ?? null),
            'expires_at' => $this->nullableString($data['expires_at'] ?? null),
            'last_used_at' => $this->nullableString($data['last_used_at'] ?? null),
            'revoked_at' => $this->nullableString($data['revoked_at'] ?? null),
        ];

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO api_key (
                kid,
                secret_hash,
                scopes,
                active,
                description,
                expires_at,
                last_used_at,
                revoked_at
            ) VALUES (
                :kid,
                :secret_hash,
                :scopes,
                :active,
                :description,
                :expires_at,
                :last_used_at,
                :revoked_at
            )
            SQL,
        );

        return $statement->execute($payload);
    }

    public function updateLastUsed(string $kid): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE api_key SET last_used_at = :last_used_at WHERE kid = :kid'
        );

        $statement->execute([
            'kid' => $kid,
            'last_used_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount() > 0;
    }

    public function revoke(string $kid): bool
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE api_key
            SET active = :active,
                revoked_at = :revoked_at
            WHERE kid = :kid
            SQL,
        );

        $statement->execute([
            'kid' => $kid,
            'active' => 0,
            'revoked_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function deserializeRow(array $row): array
    {
        $row['scopes'] = $this->decodeJsonField($row['scopes'] ?? null);
        $row['active'] = (int) ((bool) ($row['active'] ?? 0));

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
                throw new RuntimeException('Invalid JSON string provided for API key scopes field.');
            }
        }

        try {
            return (string) json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('Failed to encode API key scopes field.', previous: $exception);
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
