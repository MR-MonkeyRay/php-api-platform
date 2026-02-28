<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Middleware;

use App\Core\Error\ApiError;
use App\Core\Middleware\ApiPolicyMiddleware;
use App\Core\Policy\PolicyProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;

final class ApiPolicyMiddlewareTest extends TestCase
{
    private RequestHandlerInterface&MockObject $handler;
    private string $policyDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse(200));

        $this->policyDir = sys_get_temp_dir() . '/policy-middleware-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $files = glob($this->policyDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        if (is_dir($this->policyDir)) {
            @rmdir($this->policyDir);
        }
    }

    public function testEnabledApiPasses(): void
    {
        $this->writeSnapshot([
            'test:api:get' => [
                'enabled' => true,
                'visibility' => 'public',
                'required_scopes' => ['read'],
            ],
        ]);

        $capturedPolicy = null;
        $this->handler
            ->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function ($request) use (&$capturedPolicy): ResponseInterface {
                $capturedPolicy = $request->getAttribute(ApiPolicyMiddleware::REQUEST_POLICY_ATTRIBUTE);

                return (new ResponseFactory())->createResponse(200);
            });

        $middleware = new ApiPolicyMiddleware(new PolicyProvider($this->policyDir));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test')
            ->withAttribute(ApiPolicyMiddleware::REQUEST_API_ID_ATTRIBUTE, 'test:api:get');

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($capturedPolicy);
        self::assertSame('public', $capturedPolicy['visibility']);
        self::assertSame(['read'], $capturedPolicy['required_scopes']);
    }

    public function testDisabledApiReturns404(): void
    {
        $this->writeSnapshot([
            'test:api:get' => [
                'enabled' => false,
                'visibility' => 'public',
            ],
        ]);

        $middleware = new ApiPolicyMiddleware(new PolicyProvider($this->policyDir));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/test')
            ->withAttribute(ApiPolicyMiddleware::REQUEST_API_ID_ATTRIBUTE, 'test:api:get');

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Route not found');

        $middleware($request, $this->handler);
    }

    public function testProtectedPathWithoutApiIdReturns404(): void
    {
        $this->writeSnapshot([]);

        $middleware = new ApiPolicyMiddleware(new PolicyProvider($this->policyDir));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/without-id');

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Route not found');

        $middleware($request, $this->handler);
    }

    public function testNonProtectedPathWithoutApiIdPasses(): void
    {
        $this->writeSnapshot([]);

        $middleware = new ApiPolicyMiddleware(new PolicyProvider($this->policyDir));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAdminSystemPathBypassesPolicyRequirement(): void
    {
        $this->writeSnapshot([]);

        $middleware = new ApiPolicyMiddleware(new PolicyProvider($this->policyDir));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/system/health');

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRouteNameUsedAsApiIdWhenRequestAttributeMissing(): void
    {
        $this->writeSnapshot([
            'route:api:get' => [
                'enabled' => 1,
                'visibility' => 'private',
                'required_scopes' => [],
            ],
        ]);

        $route = $this->createMock(RouteInterface::class);
        $route
            ->method('getArgument')
            ->willReturn(null);
        $route
            ->method('getName')
            ->willReturn('route:api:get');

        $capturedApiId = null;
        $this->handler
            ->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function ($request) use (&$capturedApiId): ResponseInterface {
                $capturedApiId = $request->getAttribute(ApiPolicyMiddleware::REQUEST_API_ID_ATTRIBUTE);

                return (new ResponseFactory())->createResponse(200);
            });

        $middleware = new ApiPolicyMiddleware(new PolicyProvider($this->policyDir));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/route')
            ->withAttribute(RouteContext::ROUTE, $route);

        $response = $middleware($request, $this->handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('route:api:get', $capturedApiId);
    }

    /**
     * @param array<string, array<string, mixed>> $snapshot
     */
    private function writeSnapshot(array $snapshot): void
    {
        file_put_contents(
            $this->policyDir . '/snapshot.json',
            (string) json_encode(
                $snapshot,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );

        file_put_contents($this->policyDir . '/version', sprintf('%.6f', microtime(true)) . PHP_EOL);
    }
}
