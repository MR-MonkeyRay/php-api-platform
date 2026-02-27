<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Controller;

use App\Core\ApiKey\ApiKeyGenerator;
use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Controller\ApiKeyController;
use App\Core\Error\ApiError;
use App\Core\Repository\ApiKeyRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiKeyControllerTest extends TestCase
{
    private PDO $pdo;
    private string $versionFile;
    private ApiKeyRepository $repository;
    private ApiKeyProvider $provider;
    private ApiKeyController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE api_key (
                kid TEXT PRIMARY KEY,
                secret_hash TEXT NOT NULL,
                scopes TEXT NOT NULL DEFAULT '[]',
                active INTEGER NOT NULL DEFAULT 1,
                description TEXT,
                expires_at TEXT,
                last_used_at TEXT,
                revoked_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL
        );

        $this->versionFile = sys_get_temp_dir() . '/apikey-controller-version-' . bin2hex(random_bytes(6));

        $this->repository = new ApiKeyRepository($this->pdo);
        $this->provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);
        $this->controller = new ApiKeyController(
            $this->repository,
            $this->provider,
            new ApiKeyGenerator(),
            $this->pdo,
            'pepper',
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->versionFile)) {
            @unlink($this->versionFile);
        }

        parent::tearDown();
    }

    public function testCreateReturnsSecretAndSanitizesStoredFields(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/api-keys');
        $request->getBody()->write((string) json_encode(['scopes' => ['read']], JSON_THROW_ON_ERROR));
        $request = $request->withHeader('Content-Type', 'application/json');

        $response = $this->controller->create($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('kid', $body['data']);
        self::assertArrayHasKey('secret', $body['data']);
        self::assertArrayNotHasKey('secret_hash', $body['data']);

        $saved = $this->repository->findByKid((string) $body['data']['kid']);
        self::assertNotNull($saved);
        self::assertSame(
            hash_hmac('sha256', (string) $body['data']['secret'], 'pepper'),
            $saved['secret_hash'],
        );
    }

    public function testListDoesNotExposeSecretHash(): void
    {
        $this->repository->create([
            'kid' => 'aaaaaaaaaaaaaaaa',
            'secret_hash' => hash_hmac('sha256', 'secret', 'pepper'),
            'scopes' => ['read'],
            'active' => 1,
        ]);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/api-keys');
        $response = $this->controller->list($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['data']);
        self::assertArrayNotHasKey('secret_hash', $payload['data'][0]);
    }

    public function testGetNotFoundThrows404(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/api-keys/missing');

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('API key not found');

        $this->controller->get($request, (new ResponseFactory())->createResponse(), ['kid' => 'missing']);
    }

    public function testRevokeUpdatesApiKeyState(): void
    {
        $this->repository->create([
            'kid' => 'bbbbbbbbbbbbbbbb',
            'secret_hash' => hash_hmac('sha256', 'secret', 'pepper'),
            'scopes' => ['read'],
            'active' => 1,
        ]);

        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/admin/api-keys/bbbbbbbbbbbbbbbb');
        $response = $this->controller->revoke($request, (new ResponseFactory())->createResponse(), [
            'kid' => 'bbbbbbbbbbbbbbbb',
        ]);

        self::assertSame(204, $response->getStatusCode());

        $row = $this->repository->findByKid('bbbbbbbbbbbbbbbb');
        self::assertNotNull($row);
        self::assertSame(0, $row['active']);
        self::assertNotNull($row['revoked_at']);
    }
}
