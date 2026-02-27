<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ApplicationFactory;

abstract class AppTestCase extends TestCase
{
    protected App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = ApplicationFactory::create();
    }

    protected function request(string $method, string $uri): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);

        return $this->app->handle($request);
    }

    protected function traceIdRegex(): string
    {
        return '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    }
}
