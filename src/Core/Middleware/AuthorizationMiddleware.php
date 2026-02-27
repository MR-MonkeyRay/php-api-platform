<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Error\ApiError;
use App\Core\Error\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthorizationMiddleware
{
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

        $requiredScopes = $this->normalizeScopes($policy['required_scopes'] ?? []);
        if ($requiredScopes === []) {
            return $handler->handle($request);
        }

        $grantedScopes = $this->normalizeScopes(
            $request->getAttribute(AuthenticationMiddleware::REQUEST_SCOPES_ATTRIBUTE, []),
        );

        $missingScopes = array_values(array_diff($requiredScopes, $grantedScopes));
        if ($missingScopes !== []) {
            throw new ApiError(
                ErrorCode::FORBIDDEN,
                403,
                sprintf('API key lacks required scopes: %s', implode(', ', $missingScopes)),
            );
        }

        return $handler->handle($request);
    }

    /**
     * @return list<string>
     */
    private function normalizeScopes(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $scopes = $decoded;
            }
        }

        if (!is_array($scopes)) {
            return [];
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '') {
                continue;
            }

            $normalized[] = $scope;
        }

        return array_values(array_unique($normalized));
    }
}
