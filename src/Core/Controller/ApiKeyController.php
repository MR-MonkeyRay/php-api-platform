<?php

declare(strict_types=1);

namespace App\Core\Controller;

use App\Core\ApiKey\ApiKeyGenerator;
use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Error\ApiError;
use App\Core\Error\ErrorCode;
use App\Core\Repository\ApiKeyRepository;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiKeyController
{
    public function __construct(
        private readonly ApiKeyRepository $repository,
        private readonly ApiKeyProvider $provider,
        private readonly ApiKeyGenerator $generator,
        private readonly PDO $pdo,
        private readonly ?string $pepper = null,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = array_map(
            fn (array $row): array => $this->sanitizeApiKey($row),
            $this->repository->findAll(),
        );

        return $this->json($response, ['data' => array_values($rows)]);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kid = trim((string) ($args['kid'] ?? ''));
        if ($kid === '') {
            throw new ApiError(ErrorCode::BAD_REQUEST, 400, 'kid is required');
        }

        $row = $this->repository->findByKid($kid);
        if ($row === null) {
            throw new ApiError(ErrorCode::ROUTE_NOT_FOUND, 404, 'API key not found');
        }

        return $this->json($response, ['data' => $this->sanitizeApiKey($row)]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->parseJsonBody($request);
        $scopes = $this->normalizeScopes($payload['scopes'] ?? []);
        $description = $this->nullableString($payload['description'] ?? null);
        $expiresAt = $this->nullableString($payload['expires_at'] ?? null);

        $generated = $this->generator->generate();
        $kid = $generated['kid'];
        $secret = $generated['secret'];

        $this->repository->create([
            'kid' => $kid,
            'secret_hash' => hash_hmac('sha256', $secret, $this->resolvePepper()),
            'scopes' => $scopes,
            'active' => 1,
            'description' => $description,
            'expires_at' => $expiresAt,
        ]);

        $saved = $this->repository->findByKid($kid);
        if ($saved === null) {
            throw new ApiError(ErrorCode::INTERNAL_SERVER_ERROR, 500, 'Failed to persist API key');
        }

        $this->writeAuditLog(
            $request,
            'create',
            $kid,
            [
                'kid' => $kid,
                'scopes' => $scopes,
                'description' => $description,
                'expires_at' => $expiresAt,
            ],
        );

        $data = $this->sanitizeApiKey($saved);
        $data['secret'] = $secret;

        return $this->json($response, ['data' => $data], 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kid = trim((string) ($args['kid'] ?? ''));
        if ($kid === '') {
            throw new ApiError(ErrorCode::BAD_REQUEST, 400, 'kid is required');
        }

        $existing = $this->repository->findByKid($kid);
        if ($existing === null) {
            throw new ApiError(ErrorCode::ROUTE_NOT_FOUND, 404, 'API key not found');
        }

        $this->provider->revoke($kid);

        $this->writeAuditLog(
            $request,
            'revoke',
            $kid,
            ['kid' => $kid],
        );

        return $response->withStatus(204);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonBody(ServerRequestInterface $request): array
    {
        $rawBody = trim((string) $request->getBody());
        if ($rawBody === '') {
            return [];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new ApiError(ErrorCode::BAD_REQUEST, 400, 'Request body must be valid JSON object');
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function normalizeScopes(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            throw new ApiError(ErrorCode::BAD_REQUEST, 400, 'scopes must be an array of strings');
        }

        $scopes = [];
        foreach ($value as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '') {
                continue;
            }

            $scopes[] = $scope;
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function sanitizeApiKey(array $row): array
    {
        unset($row['secret_hash']);

        return $row;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function writeAuditLog(ServerRequestInterface $request, string $action, string $kid, array $details): void
    {
        $this->ensureAuditTableExists();

        $actor = trim($request->getHeaderLine('X-Admin-User'));
        if ($actor === '') {
            $actor = 'system';
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO audit_log (actor, action, target_type, target_id, details)
            VALUES (:actor, :action, :target_type, :target_id, :details)
            SQL
        );

        $statement->execute([
            'actor' => $actor,
            'action' => $action,
            'target_type' => 'api_key',
            'target_id' => $kid,
            'details' => (string) json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function ensureAuditTableExists(): void
    {
        $driver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        if ($driver === 'sqlite') {
            $this->pdo->exec(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS audit_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    actor TEXT NOT NULL,
                    action TEXT NOT NULL,
                    target_type TEXT NOT NULL,
                    target_id TEXT NOT NULL,
                    details TEXT NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                SQL
            );

            return;
        }

        if ($driver === 'mysql') {
            $this->pdo->exec(
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS audit_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    actor VARCHAR(255) NOT NULL,
                    action VARCHAR(64) NOT NULL,
                    target_type VARCHAR(64) NOT NULL,
                    target_id VARCHAR(255) NOT NULL,
                    details JSON NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                SQL
            );

            return;
        }

        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS audit_log (
                id BIGSERIAL PRIMARY KEY,
                actor VARCHAR(255) NOT NULL,
                action VARCHAR(64) NOT NULL,
                target_type VARCHAR(64) NOT NULL,
                target_id VARCHAR(255) NOT NULL,
                details JSONB NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL
        );
    }

    private function resolvePepper(): string
    {
        $resolved = trim((string) ($this->pepper ?? ($_ENV['API_KEY_PEPPER'] ?? getenv('API_KEY_PEPPER') ?: '')));
        if ($resolved === '') {
            throw new ApiError(ErrorCode::INTERNAL_SERVER_ERROR, 500, 'API key pepper is required');
        }

        return $resolved;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode = 200): ResponseInterface
    {
        $response->getBody()->write(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return $response
            ->withStatus($statusCode)
            ->withHeader('Content-Type', 'application/json');
    }
}
