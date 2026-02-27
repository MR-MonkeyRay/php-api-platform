<?php

declare(strict_types=1);

namespace Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ApplicationFactory;

final class ErrorResponseTest extends AppTestCase
{
    public function testUnhandledExceptionReturnsJson500WithTraceId(): void
    {
        $app = ApplicationFactory::create();
        $app->get('/error', function (): ResponseInterface {
            throw new \RuntimeException('Test exception from route');
        });

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/error');
        $response = $app->handle($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('INTERNAL_SERVER_ERROR', $payload['error']['code']);
        self::assertSame('Internal server error', $payload['error']['message']);
        self::assertMatchesRegularExpression($this->traceIdRegex(), $payload['error']['trace_id']);
    }

    public function testMethodNotAllowedReturnsJson405WithExpectedCode(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/health');
        $response = $this->app->handle($request);

        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('METHOD_NOT_ALLOWED', $payload['error']['code']);
        self::assertMatchesRegularExpression($this->traceIdRegex(), $payload['error']['trace_id']);
    }

    public function testTraceIdHeaderIsPropagatedWhenClientProvidesOne(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/unknown-route')
            ->withHeader('X-Trace-Id', 'client-trace-id-001');

        $response = $this->app->handle($request);

        self::assertSame('client-trace-id-001', $response->getHeaderLine('X-Trace-Id'));

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('client-trace-id-001', $payload['error']['trace_id']);
    }
}
