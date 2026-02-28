<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use App\Core\Middleware\AdminAuthMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AdminAuthMiddlewareTest extends TestCase
{
    private RequestHandlerInterface&MockObject $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse(200));

        $_ENV['ADMIN_USERNAME'] = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('password', PASSWORD_BCRYPT);
        putenv('ADMIN_USERNAME=admin');
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD_HASH']);
        putenv('ADMIN_USERNAME');
        putenv('ADMIN_PASSWORD_HASH');

        parent::tearDown();
    }

    public function testValidCredentialsPass(): void
    {
        $middleware = new AdminAuthMiddleware();
        $request = $this->createRequest('GET', '/admin/test')
            ->withHeader('Authorization', 'Basic ' . base64_encode('admin:password'))
            ->withAttribute('client_ip', '192.168.1.1');

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvalidCredentialsReturns401(): void
    {
        $middleware = new AdminAuthMiddleware();
        $request = $this->createRequest('GET', '/admin/test')
            ->withHeader('Authorization', 'Basic ' . base64_encode('admin:wrong'))
            ->withAttribute('client_ip', '192.168.1.1');

        $response = $middleware($request, $this->handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Basic realm="Admin"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testMissingAuthHeaderReturns401(): void
    {
        $middleware = new AdminAuthMiddleware();
        $request = $this->createRequest('GET', '/admin/test')
            ->withAttribute('client_ip', '192.168.1.1');

        $response = $middleware($request, $this->handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Basic realm="Admin"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testRateLimitingByClientIp(): void
    {
        $middleware = new AdminAuthMiddleware();

        for ($i = 0; $i < 5; $i++) {
            $request = $this->createRequest('GET', '/admin/test')
                ->withHeader('Authorization', 'Basic ' . base64_encode('admin:wrong'))
                ->withAttribute('client_ip', '10.0.0.1');
            $response = $middleware($request, $this->handler);
            self::assertSame(401, $response->getStatusCode());
        }

        $limitedRequest = $this->createRequest('GET', '/admin/test')
            ->withAttribute('client_ip', '10.0.0.1');

        $limitedResponse = $middleware($limitedRequest, $this->handler);

        self::assertSame(429, $limitedResponse->getStatusCode());
    }

    public function testRateLimitDoesNotAffectDifferentClientIp(): void
    {
        $middleware = new AdminAuthMiddleware();

        for ($i = 0; $i < 5; $i++) {
            $request = $this->createRequest('GET', '/admin/test')
                ->withHeader('Authorization', 'Basic ' . base64_encode('admin:wrong'))
                ->withAttribute('client_ip', '10.0.0.1');
            $middleware($request, $this->handler);
        }

        $otherClientRequest = $this->createRequest('GET', '/admin/test')
            ->withHeader('Authorization', 'Basic ' . base64_encode('admin:password'))
            ->withAttribute('client_ip', '10.0.0.2');

        $response = $middleware($otherClientRequest, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    private function createRequest(string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
    }
}
