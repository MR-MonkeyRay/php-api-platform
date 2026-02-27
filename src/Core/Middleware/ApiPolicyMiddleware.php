<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Error\ApiError;
use App\Core\Error\ErrorCode;
use App\Core\Policy\PolicyProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

final class ApiPolicyMiddleware
{
    public const REQUEST_POLICY_ATTRIBUTE = 'api_policy';
    public const REQUEST_API_ID_ATTRIBUTE = 'api_id';

    public function __construct(
        private readonly PolicyProvider $policyProvider,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiId = $this->resolveApiId($request);
        if ($apiId === null) {
            if ($this->requiresPolicy($request)) {
                throw new ApiError(ErrorCode::ROUTE_NOT_FOUND, 404, 'Route not found');
            }

            return $handler->handle($request);
        }

        $policy = $this->policyProvider->getPolicy($apiId);
        if (!is_array($policy) || !$this->isPolicyEnabled($policy)) {
            throw new ApiError(ErrorCode::ROUTE_NOT_FOUND, 404, 'Route not found');
        }

        $normalizedPolicy = $this->normalizePolicy($apiId, $policy);
        $request = $request
            ->withAttribute(self::REQUEST_API_ID_ATTRIBUTE, $apiId)
            ->withAttribute(self::REQUEST_POLICY_ATTRIBUTE, $normalizedPolicy);

        return $handler->handle($request);
    }

    private function resolveApiId(ServerRequestInterface $request): ?string
    {
        $attributeApiId = trim((string) $request->getAttribute(self::REQUEST_API_ID_ATTRIBUTE, ''));
        if ($attributeApiId !== '') {
            return $attributeApiId;
        }

        $route = $this->resolveRoute($request);
        if ($route === null) {
            return null;
        }

        $routeApiId = trim((string) ($route->getArgument('api_id') ?? $route->getArgument('apiId') ?? ''));
        if ($routeApiId !== '') {
            return $routeApiId;
        }

        $routeName = trim((string) ($route->getName() ?? ''));

        return $routeName === '' ? null : $routeName;
    }

    private function resolveRoute(ServerRequestInterface $request): ?RouteInterface
    {
        $route = $request->getAttribute(RouteContext::ROUTE);

        return $route instanceof RouteInterface ? $route : null;
    }

    private function requiresPolicy(ServerRequestInterface $request): bool
    {
        $path = strtolower($request->getUri()->getPath());

        return $path === '/api'
            || $path === '/admin'
            || str_starts_with($path, '/api/')
            || str_starts_with($path, '/admin/');
    }

    /**
     * @param array<string, mixed> $policy
     *
     * @return array<string, mixed>
     */
    private function normalizePolicy(string $apiId, array $policy): array
    {
        $policy['api_id'] = trim((string) ($policy['api_id'] ?? ''));
        if ($policy['api_id'] === '') {
            $policy['api_id'] = $apiId;
        }

        $policy['visibility'] = $this->normalizeVisibility($policy['visibility'] ?? 'private');
        $policy['required_scopes'] = $this->normalizeScopes($policy['required_scopes'] ?? []);

        return $policy;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function isPolicyEnabled(array $policy): bool
    {
        $value = $policy['enabled'] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function normalizeVisibility(mixed $value): string
    {
        $visibility = strtolower(trim((string) $value));

        return $visibility === 'public' ? 'public' : 'private';
    }

    /**
     * @return list<string>
     */
    private function normalizeScopes(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $scopes = [];
        foreach ($value as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '') {
                continue;
            }

            $scopes[] = $scope;
        }

        return array_values(array_unique($scopes));
    }
}
