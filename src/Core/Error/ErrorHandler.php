<?php

declare(strict_types=1);

namespace App\Core\Error;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Response;
use Throwable;

final class ErrorHandler
{
    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $traceId = $this->resolveTraceId($request);
        [$statusCode, $code, $message] = $this->resolveErrorPayload($exception, $displayErrorDetails);

        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'trace_id' => $traceId,
            ],
        ];

        if ($displayErrorDetails && $statusCode >= 500) {
            $payload['error']['detail'] = $exception->getMessage();
        }

        $response = new Response($statusCode);
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Trace-Id', $traceId);
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function resolveErrorPayload(Throwable $exception, bool $displayErrorDetails): array
    {
        if ($exception instanceof ApiError) {
            return [
                $exception->statusCode(),
                $exception->errorCode(),
                $exception->getMessage(),
            ];
        }

        if ($exception instanceof HttpNotFoundException) {
            return [404, ErrorCode::ROUTE_NOT_FOUND, 'Route not found'];
        }

        if ($exception instanceof HttpMethodNotAllowedException) {
            return [405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed'];
        }

        if ($exception instanceof HttpBadRequestException) {
            return [400, ErrorCode::BAD_REQUEST, 'Bad request'];
        }

        if ($exception instanceof HttpUnauthorizedException) {
            return [401, ErrorCode::UNAUTHORIZED, 'Unauthorized'];
        }

        if ($exception instanceof HttpForbiddenException) {
            return [403, ErrorCode::FORBIDDEN, 'Forbidden'];
        }

        if ($exception instanceof HttpException) {
            $statusCode = $exception->getCode();
            if (!is_int($statusCode) || $statusCode < 400 || $statusCode > 599) {
                $statusCode = 500;
            }

            $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'HTTP error';
            if ($statusCode >= 500 && !$displayErrorDetails) {
                $message = 'Internal server error';
            }

            return [$statusCode, ErrorCode::HTTP_ERROR, $message];
        }

        $message = $displayErrorDetails
            ? ($exception->getMessage() !== '' ? $exception->getMessage() : 'Unhandled exception')
            : 'Internal server error';

        return [500, ErrorCode::INTERNAL_SERVER_ERROR, $message];
    }

    private function resolveTraceId(ServerRequestInterface $request): string
    {
        $traceId = $request->getAttribute('trace_id');
        if (is_string($traceId) && $traceId !== '') {
            return $traceId;
        }

        return self::generateTraceId();
    }

    public static function generateTraceId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
