<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Error\ApiError;
use App\Core\Middleware\ApiPolicyMiddleware;
use App\Core\Middleware\AuthenticationMiddleware;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuthenticationMiddlewareTest extends TestCase
{
    private PDO $pdo;
    private string $versionFile;
    private ApiKeyProvider $apiKeyProvider;
    private RequestHandlerInterface&MockObject $handler;

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

        $this->versionFile = sys_get_temp_dir() . '/auth-mw-version-' . bin2hex(random_bytes(6));
        $this->apiKeyProvider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);

        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse(200));
    }

    protected function tearDown(): void
    {
        if (is_file($this->versionFile)) {
            @unlink($this->versionFile);
        }

        parent::tearDown();
    }

    public function testPublicApiSkipsAuth(): void
    {
        $middleware = new AuthenticationMiddleware($this->apiKeyProvider);
        $request = $this->requestWithPolicy(['visibility' => 'public']);

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPrivateApiRequiresKey(): void
    {
        $middleware = new AuthenticationMiddleware($this->apiKeyProvider);
        $request = $this->requestWithPolicy(['visibility' => 'private']);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('API key is required');

        $middleware($request, $this->handler);
    }

    public function testValidKeyPasses(): void
    {
        $this->createApiKey(
            kid: 'test',
            secretHash: hash_hmac('sha256', 'secret', 'pepper'),
            scopes: ['read'],
        );

        $capturedScopes = null;
        $this->handler
            ->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function ($request) use (&$capturedScopes): ResponseInterface {
                $capturedScopes = $request->getAttribute(AuthenticationMiddleware::REQUEST_SCOPES_ATTRIBUTE);

                return (new ResponseFactory())->createResponse(200);
            });

        $middleware = new AuthenticationMiddleware($this->apiKeyProvider);
        $request = $this->requestWithPolicy(['visibility' => 'private'])
            ->withHeader('X-API-Key', 'test.secret');

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['read'], $capturedScopes);
    }

    public function testInvalidKeyReturns401(): void
    {
        $this->createApiKey(
            kid: 'test',
            secretHash: hash_hmac('sha256', 'correct', 'pepper'),
            scopes: ['read'],
        );

        $middleware = new AuthenticationMiddleware($this->apiKeyProvider);
        $request = $this->requestWithPolicy(['visibility' => 'private'])
            ->withHeader('X-API-Key', 'test.invalid');

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('API key is invalid or revoked');

        $middleware($request, $this->handler);
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function requestWithPolicy(array $policy): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test')
            ->withAttribute(ApiPolicyMiddleware::REQUEST_POLICY_ATTRIBUTE, $policy);
    }

    /**
     * @param list<string> $scopes
     */
    private function createApiKey(string $kid, string $secretHash, array $scopes): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO api_key (kid, secret_hash, scopes, active)
            VALUES (:kid, :secret_hash, :scopes, 1)
            SQL
        );

        $statement->execute([
            'kid' => $kid,
            'secret_hash' => $secretHash,
            'scopes' => (string) json_encode($scopes, JSON_THROW_ON_ERROR),
        ]);
    }
}
