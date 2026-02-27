<?php

declare(strict_types=1);

namespace App\Core\Controller;

use App\Core\Error\ApiError;
use App\Core\Error\ErrorCode;
use App\Core\Policy\SnapshotBuilder;
use App\Core\Repository\ApiPolicyRepository;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiPolicyController
{
    public function __construct(
        private readonly ApiPolicyRepository $repository,
        private readonly SnapshotBuilder $snapshotBuilder,
        private readonly PDO $pdo,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['data' => $this->repository->findAll()]);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $apiId = trim((string) ($args['apiId'] ?? ''));
        if ($apiId === '') {
            throw new ApiError(ErrorCode::BAD_REQUEST, 400, 'apiId is required');
        }

        $policy = $this->repository->findByApiId($apiId);
        if ($policy === null) {
            throw new ApiError(ErrorCode::ROUTE_NOT_FOUND, 404, 'Policy not found');
        }

        return $this->json($response, ['data' => $policy]);
    }

    public function upsert(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->parseJsonBody($request);

        $apiId = trim((string) ($payload['api_id'] ?? ''));
        if ($apiId === '') {
            throw new ApiError(ErrorCode::BAD_REQUEST, 400, 'api_id is required');
        }

        $existing = $this->repository->findByApiId($apiId);
        $this->repository->upsert($payload);

        $this->snapshotBuilder->build([]);

        $saved = $this->repository->findByApiId($apiId);
        if ($saved === null) {
            throw new ApiError(ErrorCode::INTERNAL_SERVER_ERROR, 500, 'Failed to persist API policy');
        }

        $this->writeAuditLog($request, $apiId, $payload, $existing === null ? 'create' : 'update');

        return $this->json($response, ['data' => $saved], $existing === null ? 201 : 200);
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
     * @param array<string, mixed> $payload
     */
    private function writeAuditLog(ServerRequestInterface $request, string $apiId, array $payload, string $action): void
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
            'target_type' => 'api_policy',
            'target_id' => $apiId,
            'details' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
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
