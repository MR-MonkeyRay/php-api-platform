<?php

declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Core\Error\ErrorHandler;
use App\Core\Middleware\AdminAuthMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AdminAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    public function testAdminRouteRequiresAuth(): void
    {
        $app = $this->createApp();

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/plugins')
            ->withAttribute('client_ip', '127.0.0.1');

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Basic realm="Admin"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testValidAdminAuth(): void
    {
        $app = $this->createApp();

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/plugins')
            ->withHeader('Authorization', 'Basic ' . base64_encode('admin:password'))
            ->withAttribute('client_ip', '127.0.0.1');

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRateLimitingOnAdminRoute(): void
    {
        $app = $this->createApp();

        for ($i = 0; $i < 5; $i++) {
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', '/admin/plugins')
                ->withHeader('Authorization', 'Basic ' . base64_encode('admin:wrong'))
                ->withAttribute('client_ip', '172.16.10.20');
            $response = $app->handle($request);
            self::assertSame(401, $response->getStatusCode());
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/plugins')
            ->withAttribute('client_ip', '172.16.10.20');
        $response = $app->handle($request);

        self::assertSame(429, $response->getStatusCode());
    }

    private function createApp(): \Slim\App
    {
        $app = AppFactory::create();

        $app->get('/admin/plugins', static function ($request, $response) {
            $response->getBody()->write('{"ok":true}');

            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->add(new AdminAuthMiddleware());
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

        return $app;
    }
}
