<?php

declare(strict_types=1);

namespace Tests\Integration;

use Slim\App;
use Tests\Support\ApplicationFactory;

final class SmokeTest extends AppTestCase
{
    public function testApplicationBoots(): void
    {
        $app = ApplicationFactory::create();

        self::assertInstanceOf(App::class, $app);
    }

    public function testHealthEndpointReturns200(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testHealthEndpointReturnsJson(): void
    {
        $response = $this->request('GET', '/health');

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertArrayHasKey('timestamp', $payload);
    }

    public function testUnknownRouteReturnsJson404WithTraceId(): void
    {
        $response = $this->request('GET', '/unknown-route');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
        self::assertSame('ROUTE_NOT_FOUND', $payload['error']['code']);
        self::assertMatchesRegularExpression($this->traceIdRegex(), $payload['error']['trace_id']);
        self::assertMatchesRegularExpression($this->traceIdRegex(), $response->getHeaderLine('X-Trace-Id'));
    }
}
