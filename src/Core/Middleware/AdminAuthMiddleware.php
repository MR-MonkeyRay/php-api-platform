<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Audit\AuditLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class AdminAuthMiddleware
{
    private const REALM = 'Admin';
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    private ?AuditLogger $auditLogger = null;

    /**
     * @var array<string, list<int>>
     */
    private array $failedAttemptsByIp = [];

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->shouldProtectPath($request)) {
            return $handler->handle($request);
        }

        $clientIp = $this->resolveClientIp($request);
        $now = time();

        if ($this->isRateLimited($clientIp, $now)) {
            $this->writeAuditLog('admin.login_failed', 'unknown', $clientIp, [
                'reason' => 'rate_limited',
            ]);

            return $this->createRateLimitResponse();
        }

        $credentials = $this->extractBasicCredentials($request);
        if ($credentials === null || !$this->credentialsAreValid($credentials['username'], $credentials['password'])) {
            $this->registerFailedAttempt($clientIp, $now);

            $this->writeAuditLog(
                'admin.login_failed',
                $credentials['username'] ?? 'unknown',
                $clientIp,
                ['reason' => 'invalid_credentials'],
            );

            return $this->createUnauthorizedResponse();
        }

        $this->clearFailedAttempts($clientIp);

        $username = $credentials['username'];
        $request = $request->withAttribute('admin_user', $username);
        $this->writeAuditLog('admin.login_succeeded', $username, $clientIp, []);

        return $handler->handle($request);
    }

    private function shouldProtectPath(ServerRequestInterface $request): bool
    {
        $path = strtolower($request->getUri()->getPath());
        if ($path === '/admin/system/health') {
            return false;
        }

        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $attributeIp = $request->getAttribute('client_ip');
        if (is_string($attributeIp) && trim($attributeIp) !== '') {
            return trim($attributeIp);
        }

        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if (is_string($remoteAddr) && trim($remoteAddr) !== '') {
            return trim($remoteAddr);
        }

        return 'unknown';
    }

    private function isRateLimited(string $clientIp, int $now): bool
    {
        $this->pruneExpiredAttempts($clientIp, $now);

        return count($this->failedAttemptsByIp[$clientIp] ?? []) >= self::RATE_LIMIT_MAX_ATTEMPTS;
    }

    private function registerFailedAttempt(string $clientIp, int $now): void
    {
        $this->pruneExpiredAttempts($clientIp, $now);
        $this->failedAttemptsByIp[$clientIp][] = $now;
    }

    private function clearFailedAttempts(string $clientIp): void
    {
        unset($this->failedAttemptsByIp[$clientIp]);
    }

    private function pruneExpiredAttempts(string $clientIp, int $now): void
    {
        $attempts = $this->failedAttemptsByIp[$clientIp] ?? [];
        if ($attempts === []) {
            return;
        }

        $threshold = $now - self::RATE_LIMIT_WINDOW_SECONDS;
        $attempts = array_values(array_filter(
            $attempts,
            static fn (int $timestamp): bool => $timestamp > $threshold,
        ));

        if ($attempts === []) {
            unset($this->failedAttemptsByIp[$clientIp]);

            return;
        }

        $this->failedAttemptsByIp[$clientIp] = $attempts;
    }

    /**
     * @return array{username: string, password: string}|null
     */
    private function extractBasicCredentials(ServerRequestInterface $request): ?array
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if ($authorization === '' || stripos($authorization, 'Basic ') !== 0) {
            return null;
        }

        $encodedPayload = trim(substr($authorization, 6));
        if ($encodedPayload === '') {
            return null;
        }

        $decodedPayload = base64_decode($encodedPayload, true);
        if (!is_string($decodedPayload) || $decodedPayload === '') {
            return null;
        }

        $parts = explode(':', $decodedPayload, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $username = trim($parts[0]);
        $password = $parts[1];

        if ($username === '' || $password === '') {
            return null;
        }

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    private function credentialsAreValid(string $username, string $password): bool
    {
        $expectedUsername = $this->readEnv('ADMIN_USERNAME');
        $expectedPasswordHash = $this->readEnv('ADMIN_PASSWORD_HASH');

        if ($expectedUsername === '' || $expectedPasswordHash === '') {
            return false;
        }

        if (!hash_equals($expectedUsername, $username)) {
            return false;
        }

        return password_verify($password, $expectedPasswordHash);
    }

    private function readEnv(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function createUnauthorizedResponse(): ResponseInterface
    {
        return $this->createJsonResponse(
            401,
            [
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Admin authentication required',
                ],
            ],
            [
                'WWW-Authenticate' => sprintf('Basic realm="%s"', self::REALM),
            ],
        );
    }

    private function createRateLimitResponse(): ResponseInterface
    {
        return $this->createJsonResponse(
            429,
            [
                'error' => [
                    'code' => 'TOO_MANY_REQUESTS',
                    'message' => 'Too many failed admin authentication attempts',
                ],
            ],
            [
                'Retry-After' => (string) self::RATE_LIMIT_WINDOW_SECONDS,
            ],
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function writeAuditLog(string $action, string $actor, string $ip, array $details): void
    {
        try {
            $this->resolveAuditLogger()->log(
                $action,
                $actor === '' ? 'unknown' : $actor,
                $details,
                $ip,
            );
        } catch (\Throwable) {
            // 审计日志失败不能阻断管理认证流程
        }
    }

    private function resolveAuditLogger(): AuditLogger
    {
        if ($this->auditLogger instanceof AuditLogger) {
            return $this->auditLogger;
        }

        $this->auditLogger = AuditLogger::fromEnvironment();

        return $this->auditLogger;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function createJsonResponse(int $statusCode, array $payload, array $headers = []): ResponseInterface
    {
        $response = new Response($statusCode);
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
        $response = $response->withHeader('Content-Type', 'application/json');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
