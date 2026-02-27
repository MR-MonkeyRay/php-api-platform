<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\ApiKey\ApiKey;
use App\Core\ApiKey\ApiKeyProvider;
use App\Core\Error\ApiError;
use App\Core\Error\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthenticationMiddleware
{
    public const REQUEST_API_KEY_ATTRIBUTE = 'api_key';
    public const REQUEST_API_KEY_SCOPES_ATTRIBUTE = 'api_key_scopes';
    public const REQUEST_API_KEY_KID_ATTRIBUTE = 'api_key_kid';
    public const REQUEST_SCOPES_ATTRIBUTE = 'scopes';

    public function __construct(
        private readonly ApiKeyProvider $apiKeyProvider,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $policy = $request->getAttribute(ApiPolicyMiddleware::REQUEST_POLICY_ATTRIBUTE);
        if (!is_array($policy)) {
            return $handler->handle($request);
        }

        $visibility = strtolower(trim((string) ($policy['visibility'] ?? 'private')));
        if ($visibility === 'public') {
            return $handler->handle($request);
        }

        $header = trim($request->getHeaderLine('X-API-Key'));
        if ($header === '') {
            throw new ApiError(ErrorCode::UNAUTHORIZED, 401, 'API key is required');
        }

        [$kid, $secret] = $this->parseApiKeyHeader($header);
        $apiKey = $this->apiKeyProvider->validate($kid, $secret);
        if (!$apiKey instanceof ApiKey) {
            throw new ApiError(ErrorCode::UNAUTHORIZED, 401, 'API key is invalid or revoked');
        }

        $request = $request
            ->withAttribute(self::REQUEST_API_KEY_ATTRIBUTE, $apiKey)
            ->withAttribute(self::REQUEST_API_KEY_KID_ATTRIBUTE, $apiKey->kid)
            ->withAttribute(self::REQUEST_API_KEY_SCOPES_ATTRIBUTE, $apiKey->scopes)
            ->withAttribute(self::REQUEST_SCOPES_ATTRIBUTE, $apiKey->scopes);

        return $handler->handle($request);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseApiKeyHeader(string $header): array
    {
        $parts = explode('.', $header, 2);
        if (count($parts) !== 2) {
            throw new ApiError(ErrorCode::UNAUTHORIZED, 401, 'API key format is invalid');
        }

        $kid = trim($parts[0]);
        $secret = trim($parts[1]);
        if ($kid === '' || $secret === '') {
            throw new ApiError(ErrorCode::UNAUTHORIZED, 401, 'API key format is invalid');
        }

        return [$kid, $secret];
    }
}
