<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Error\ErrorHandler;
use Monolog\LogRecord;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class TraceContextMiddleware
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $traceId = trim($request->getHeaderLine('X-Trace-Id'));
        if ($traceId === '') {
            $traceId = ErrorHandler::generateTraceId();
        }

        $request = $request->withAttribute('trace_id', $traceId);

        $processorAttached = false;
        if ($this->logger instanceof Logger) {
            $this->logger->pushProcessor(
                static function (LogRecord $record) use ($traceId): LogRecord {
                    $context = $record->context;
                    if (!isset($context['trace_id']) || !is_string($context['trace_id']) || $context['trace_id'] === '') {
                        $context = ['trace_id' => $traceId] + $context;
                    }

                    $extra = $record->extra;
                    if (!isset($extra['trace_id']) || !is_string($extra['trace_id']) || $extra['trace_id'] === '') {
                        $extra = ['trace_id' => $traceId] + $extra;
                    }

                    return $record->with(context: $context, extra: $extra);
                }
            );
            $processorAttached = true;
        }

        try {
            $response = $handler->handle($request);
        } finally {
            if ($processorAttached) {
                $this->logger->popProcessor();
            }
        }

        return $response->withHeader('X-Trace-Id', $traceId);
    }
}
