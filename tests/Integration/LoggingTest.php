<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Error\ErrorHandler;
use App\Core\Logger\LoggerFactory;
use App\Core\Middleware\TraceContextMiddleware;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class LoggingTest extends AppTestCase
{
    public function testRequestGeneratesLogWithTraceId(): void
    {
        $factory = new LoggerFactory();
        $logger = $factory->create('http');
        self::assertInstanceOf(Logger::class, $logger);

        $capture = new TestHandler(Level::Debug);
        $logger->setHandlers([$capture]);

        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add(new TraceContextMiddleware($logger));

        $app->get(
            '/health',
            function (ServerRequestInterface $request, ResponseInterface $response) use ($logger): ResponseInterface {
                $traceId = $request->getAttribute('trace_id');
                $logger->info('Health check executed', ['trace_id' => is_string($traceId) ? $traceId : null]);

                $response->getBody()->write('{"status":"ok"}');

                return $response->withHeader('Content-Type', 'application/json');
            }
        );

        $errorMiddleware = $app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/health')
            ->withHeader('X-Trace-Id', 'trace-integration-001');

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('trace-integration-001', $response->getHeaderLine('X-Trace-Id'));

        $records = $capture->getRecords();
        self::assertNotEmpty($records);

        $record = $records[0];
        self::assertSame('trace-integration-001', $record->context['trace_id'] ?? null);
        self::assertSame('trace-integration-001', $record->extra['trace_id'] ?? null);
    }

    public function testMiddlewareInjectsTraceIdWhenHeaderMissing(): void
    {
        $factory = new LoggerFactory();
        $logger = $factory->create('http');
        self::assertInstanceOf(Logger::class, $logger);

        $capture = new TestHandler(Level::Debug);
        $logger->setHandlers([$capture]);

        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add(new TraceContextMiddleware($logger));

        $app->get(
            '/log',
            function (ServerRequestInterface $request, ResponseInterface $response) use ($logger): ResponseInterface {
                $logger->info('generated trace id test');

                return $response;
            }
        );

        $errorMiddleware = $app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler());

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/log');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $traceId = $response->getHeaderLine('X-Trace-Id');
        self::assertMatchesRegularExpression($this->traceIdRegex(), $traceId);

        $records = $capture->getRecords();
        self::assertCount(1, $records);
        self::assertSame($traceId, $records[0]->context['trace_id'] ?? null);
        self::assertSame($traceId, $records[0]->extra['trace_id'] ?? null);
    }
}
