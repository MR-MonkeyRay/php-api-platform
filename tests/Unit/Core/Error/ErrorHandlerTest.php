<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Error;

use App\Core\Error\ApiError;
use App\Core\Error\ErrorCode;
use App\Core\Error\ErrorHandler;
use PHPUnit\Framework\TestCase;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ErrorHandlerTest extends TestCase
{
    private ErrorHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ErrorHandler();
    }

    public function testHandlesApiErrorWithCustomStatusAndCode(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/test')
            ->withAttribute('trace_id', 'trace-test-001');

        $exception = new ApiError('CUSTOM_CODE', 422, 'Validation failed');

        $response = ($this->handler)(
            $request,
            $exception,
            false,
            false,
            false,
        );

        self::assertSame(422, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('CUSTOM_CODE', $payload['error']['code']);
        self::assertSame('Validation failed', $payload['error']['message']);
        self::assertSame('trace-test-001', $payload['error']['trace_id']);
    }

    public function testHandlesNotFoundExceptionAsJson404(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/unknown');
        $exception = new HttpNotFoundException($request);

        $response = ($this->handler)(
            $request,
            $exception,
            false,
            false,
            false,
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ErrorCode::ROUTE_NOT_FOUND, $payload['error']['code']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $payload['error']['trace_id']
        );
    }

    public function testHandlesMethodNotAllowedAsJson405(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/health');
        $exception = (new HttpMethodNotAllowedException($request))->setAllowedMethods(['GET']);

        $response = ($this->handler)(
            $request,
            $exception,
            false,
            false,
            false,
        );

        self::assertSame(405, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ErrorCode::METHOD_NOT_ALLOWED, $payload['error']['code']);
        self::assertSame('Method not allowed', $payload['error']['message']);
    }

    public function testHidesInternalErrorMessageWhenDisplayErrorDetailsIsFalse(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/error');

        $response = ($this->handler)(
            $request,
            new \RuntimeException('Sensitive message'),
            false,
            false,
            false,
        );

        self::assertSame(500, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ErrorCode::INTERNAL_SERVER_ERROR, $payload['error']['code']);
        self::assertSame('Internal server error', $payload['error']['message']);
        self::assertArrayNotHasKey('detail', $payload['error']);
    }

    public function testIncludesErrorDetailWhenDisplayErrorDetailsIsTrue(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/error');

        $response = ($this->handler)(
            $request,
            new \RuntimeException('Sensitive message'),
            true,
            false,
            false,
        );

        self::assertSame(500, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ErrorCode::INTERNAL_SERVER_ERROR, $payload['error']['code']);
        self::assertSame('Sensitive message', $payload['error']['message']);
        self::assertSame('Sensitive message', $payload['error']['detail']);
    }
}
