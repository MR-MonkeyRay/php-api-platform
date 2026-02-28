<?php

declare(strict_types=1);

namespace App\Core\Audit;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AuditLogger
{
    private function __construct(private readonly string $logFile)
    {
    }

    public static function fromEnvironment(): self
    {
        $configured = trim((string) ($_ENV['ADMIN_AUDIT_LOG_FILE'] ?? getenv('ADMIN_AUDIT_LOG_FILE') ?: ''));
        $logFile = $configured === '' ? 'var/audit/admin.log' : $configured;

        return new self($logFile);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(string $action, string $actor, array $details, string $ip = 'unknown'): void
    {
        $line = [
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'action' => $action,
            'actor' => $actor,
            'details' => $details,
            'ip' => $ip === '' ? 'unknown' : $ip,
        ];

        $encoded = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $this->resolvePath($this->logFile);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create audit log directory: %s', $directory));
        }

        $result = file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            throw new RuntimeException(sprintf('Failed to write audit log: %s', $path));
        }
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return getcwd() . '/var/audit/admin.log';
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        $cwd = getcwd() ?: '.';

        return rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }
}
