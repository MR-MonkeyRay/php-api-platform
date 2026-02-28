<?php

declare(strict_types=1);

namespace App\Core\Setup;

use JsonException;
use RuntimeException;

final class SetupDetector
{
    private readonly string $varDir;

    public function __construct(
        string $varDir = 'var',
        private readonly int $tokenTtlSeconds = 3600,
    ) {
        if ($this->tokenTtlSeconds <= 0) {
            throw new RuntimeException('Setup token TTL must be greater than 0 seconds.');
        }

        $this->varDir = $this->resolveVarDir($varDir);
    }

    public function isInstalled(): bool
    {
        return is_file($this->installedMarkerFile());
    }

    public function markInstalled(): void
    {
        $this->ensureVarDirectoryExists();

        $installedAt = date(DATE_ATOM) . PHP_EOL;
        if (file_put_contents($this->installedMarkerFile(), $installedAt, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write installation marker file.');
        }
    }

    public function generateSetupToken(): string
    {
        $this->ensureVarDirectoryExists();

        $token = bin2hex(random_bytes(32));

        $payload = [
            'token' => $token,
            'expires_at' => time() + $this->tokenTtlSeconds,
            'created_at' => time(),
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode setup token payload.');
        }

        $this->atomicWrite($this->tokenFile(), $json . PHP_EOL);

        return $token;
    }

    public function validateSetupToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || strlen($token) !== 64) {
            return false;
        }

        $payload = $this->readTokenPayload();
        if ($payload === null) {
            return false;
        }

        $storedToken = trim((string) ($payload['token'] ?? ''));
        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        if ($storedToken === '' || strlen($storedToken) !== 64 || $expiresAt <= 0) {
            return false;
        }

        if ($expiresAt < time()) {
            @unlink($this->tokenFile());

            return false;
        }

        return hash_equals($storedToken, $token);
    }

    public function consumeSetupToken(string $token): bool
    {
        if (!$this->validateSetupToken($token)) {
            return false;
        }

        $tokenFile = $this->tokenFile();
        if (!is_file($tokenFile)) {
            return false;
        }

        return unlink($tokenFile);
    }

    private function ensureVarDirectoryExists(): void
    {
        if (is_dir($this->varDir)) {
            return;
        }

        if (!mkdir($this->varDir, 0755, true) && !is_dir($this->varDir)) {
            throw new RuntimeException(sprintf('Failed to create setup directory: %s', $this->varDir));
        }
    }

    private function installedMarkerFile(): string
    {
        return $this->varDir . DIRECTORY_SEPARATOR . '.installed';
    }

    private function tokenFile(): string
    {
        return $this->varDir . DIRECTORY_SEPARATOR . '.setup-token';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readTokenPayload(): ?array
    {
        $tokenFile = $this->tokenFile();
        if (!is_file($tokenFile)) {
            return null;
        }

        $content = file_get_contents($tokenFile);
        if (!is_string($content) || $content === '') {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function atomicWrite(string $targetFile, string $content): void
    {
        $tmpFile = sprintf('%s.tmp.%s', $targetFile, bin2hex(random_bytes(6)));

        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write temporary setup file: %s', $tmpFile));
        }

        if (!rename($tmpFile, $targetFile)) {
            @unlink($tmpFile);
            throw new RuntimeException(sprintf('Failed to replace setup file atomically: %s', $targetFile));
        }
    }

    private function resolveVarDir(string $varDir): string
    {
        $trimmed = trim($varDir);
        if ($trimmed === '') {
            throw new RuntimeException('Setup var directory cannot be empty.');
        }

        if ($this->isAbsolutePath($trimmed)) {
            return rtrim($trimmed, DIRECTORY_SEPARATOR);
        }

        $projectRoot = dirname(__DIR__, 3);

        return rtrim($projectRoot . DIRECTORY_SEPARATOR . ltrim($trimmed, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }
}
