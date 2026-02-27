<?php

declare(strict_types=1);

namespace App\Core\Policy;

final class PolicyProvider
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $apcuCacheFallback = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $snapshot = [];

    private readonly string $policyDirectory;
    private readonly string $snapshotPath;
    private readonly string $versionPath;
    private readonly float $debounceSeconds;

    private ?int $lastVersionMTime = null;
    private float $lastReloadAt = 0.0;

    public function __construct(
        string $policyDirectory = 'var/policy',
        float $debounceMilliseconds = 500.0,
    ) {
        $this->policyDirectory = $this->resolvePolicyDirectory($policyDirectory);
        $this->snapshotPath = $this->policyDirectory . DIRECTORY_SEPARATOR . 'snapshot.json';
        $this->versionPath = $this->policyDirectory . DIRECTORY_SEPARATOR . 'version';
        $this->debounceSeconds = max($debounceMilliseconds / 1000, 0.0);

        $this->reloadSnapshot(true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPolicy(string $apiId): ?array
    {
        $apiId = trim($apiId);
        if ($apiId === '') {
            return null;
        }

        $this->reloadIfNeeded();

        $cacheKey = $this->cacheKey($apiId);
        $cached = $this->cacheFetch($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $policy = $this->snapshot[$apiId] ?? null;
        if (is_array($policy)) {
            $this->cacheStore($cacheKey, $policy);
        }

        return is_array($policy) ? $policy : null;
    }

    private function reloadIfNeeded(): void
    {
        if (!$this->versionFileChanged()) {
            return;
        }

        $now = microtime(true);
        if (($now - $this->lastReloadAt) < $this->debounceSeconds) {
            return;
        }

        $this->reloadSnapshot();
    }

    private function versionFileChanged(): bool
    {
        if (!is_file($this->versionPath)) {
            return $this->lastVersionMTime !== null;
        }

        $mtime = @filemtime($this->versionPath);
        if ($mtime === false) {
            return false;
        }

        return $this->lastVersionMTime === null || $mtime !== $this->lastVersionMTime;
    }

    private function reloadSnapshot(bool $force = false): void
    {
        $now = microtime(true);
        if (!$force && ($now - $this->lastReloadAt) < $this->debounceSeconds) {
            return;
        }

        $loaded = $this->loadSnapshot();
        if ($loaded !== null) {
            $this->snapshot = $loaded;
            $this->invalidateCache();
        }

        $this->lastVersionMTime = is_file($this->versionPath)
            ? (@filemtime($this->versionPath) ?: null)
            : null;
        $this->lastReloadAt = $now;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function loadSnapshot(): ?array
    {
        if (!is_file($this->snapshotPath)) {
            return [];
        }

        $json = @file_get_contents($this->snapshotPath);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        $normalized = [];
        foreach ($decoded as $apiId => $policy) {
            if (!is_string($apiId) || trim($apiId) === '' || !is_array($policy)) {
                continue;
            }

            $normalized[$apiId] = $policy;
        }

        return $normalized;
    }

    private function cacheKey(string $apiId): string
    {
        return 'policy:' . $apiId;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function cacheFetch(string $key): array|false
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $success = false;
            $value = apcu_fetch($key, $success);

            return $success && is_array($value) ? $value : false;
        }

        $value = self::$apcuCacheFallback[$key] ?? null;

        return is_array($value) ? $value : false;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function cacheStore(string $key, array $policy): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_store($key, $policy);

            return;
        }

        self::$apcuCacheFallback[$key] = $policy;
    }

    private function invalidateCache(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            if (function_exists('apcu_clear_cache')) {
                apcu_clear_cache();
            }

            return;
        }

        self::$apcuCacheFallback = [];
    }

    private function resolvePolicyDirectory(string $directory): string
    {
        if ($directory === '') {
            return $this->projectRoot() . DIRECTORY_SEPARATOR . 'var/policy';
        }

        if ($this->isAbsolutePath($directory)) {
            return rtrim($directory, DIRECTORY_SEPARATOR);
        }

        return rtrim($this->projectRoot() . DIRECTORY_SEPARATOR . $directory, DIRECTORY_SEPARATOR);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }
}
