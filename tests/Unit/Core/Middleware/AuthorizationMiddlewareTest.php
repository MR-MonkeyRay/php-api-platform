<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use App\Core\Error\ApiError;
use App\Core\Middleware\ApiPolicyMiddleware;
use App\Core\Middleware\AuthenticationMiddleware;
use App\Core\Middleware\AuthorizationMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuthorizationMiddlewareTest extends TestCase
{
    private RequestHandlerInterface&MockObject $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse(200));
    }

    public function testSufficientScopesPass(): void
    {
        $middleware = new AuthorizationMiddleware();
        $request = $this->requestWithPolicy([
            'visibility' => 'private',
            'required_scopes' => ['read'],
        ])->withAttribute(AuthenticationMiddleware::REQUEST_SCOPES_ATTRIBUTE, ['read', 'write']);

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInsufficientScopesReturns403(): void
    {
        $middleware = new AuthorizationMiddleware();
        $request = $this->requestWithPolicy([
            'visibility' => 'private',
            'required_scopes' => ['admin'],
        ])->withAttribute(AuthenticationMiddleware::REQUEST_SCOPES_ATTRIBUTE, ['read']);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('API key lacks required scopes: admin');

        $middleware($request, $this->handler);
    }

    public function testPublicApiSkipsAuthorization(): void
    {
        $middleware = new AuthorizationMiddleware();
        $request = $this->requestWithPolicy([
            'visibility' => 'public',
            'required_scopes' => ['admin'],
        ]);

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
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
}
