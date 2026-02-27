<?php

declare(strict_types=1);

namespace App\Core\ApiKey;

use App\Core\Repository\ApiKeyRepository;
use PDO;
use RuntimeException;

final class ApiKeyProvider
{
    private const CACHE_PREFIX = 'api_key:';
    private const DEFAULT_CACHE_TTL_SECONDS = 30;
    private const NEGATIVE_CACHE_TTL_SECONDS = 5;

    /**
     * @var array<string, array{expires_at: float, value: array<string, mixed>|null}>
     */
    private static array $cacheFallback = [];

    /**
     * @var array<string, true>
     */
    private static array $knownCacheKeys = [];

    private readonly ApiKeyRepository $repository;
    private readonly string $pepper;
    private readonly int $cacheTtlSeconds;
    private readonly string $versionFile;
    private readonly string $cacheNamespace;

    private ?int $lastVersionMTime;

    public function __construct(
        PDO $pdo,
        ?string $pepper = null,
        string $versionFile = 'var/apikey.version',
        int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
    ) {
        $this->repository = new ApiKeyRepository($pdo);
        $this->pepper = $this->resolvePepper($pepper);
        $this->cacheTtlSeconds = max(1, $cacheTtlSeconds);
        $this->versionFile = $this->resolveVersionFile($versionFile);
        $this->cacheNamespace = substr(hash('sha256', $this->versionFile), 0, 16);
        $this->lastVersionMTime = $this->readVersionMTime();
    }

    public function validate(string $kid, string $secret): ?ApiKey
    {
        $this->invalidateCacheIfVersionChanged();

        $kid = trim($kid);
        if ($kid === '' || $secret === '') {
            return null;
        }

        $record = $this->findKeyRecord($kid);
        if ($record === null) {
            return null;
        }

        if (!$this->isRecordUsable($record)) {
            return null;
        }

        $storedSecretHash = trim((string) ($record['secret_hash'] ?? ''));
        if ($storedSecretHash === '') {
            return null;
        }

        $calculatedHash = hash_hmac('sha256', $secret, $this->pepper);
        if (!hash_equals($storedSecretHash, $calculatedHash)) {
            return null;
        }

        $this->repository->updateLastUsed($kid);

        return $this->hydrateApiKey($record);
    }

    public function revoke(string $kid): void
    {
        $kid = trim($kid);
        if ($kid === '') {
            return;
        }

        $this->repository->revoke($kid);
        $this->forgetCache($kid);
        $this->bumpVersionFileMTime();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findKeyRecord(string $kid): ?array
    {
        $cacheKey = $this->cacheKey($kid);
        $cached = $this->cacheFetch($cacheKey);
        if ($cached['hit']) {
            return $cached['value'];
        }

        $record = $this->repository->findByKid($kid);
        if (!is_array($record)) {
            $this->cacheStore($cacheKey, null, self::NEGATIVE_CACHE_TTL_SECONDS);

            return null;
        }

        $this->cacheStore($cacheKey, $record, $this->cacheTtlSeconds);

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function hydrateApiKey(array $record): ApiKey
    {
        return new ApiKey(
            kid: (string) ($record['kid'] ?? ''),
            scopes: $this->normalizeScopes($record['scopes'] ?? []),
            active: ((int) ($record['active'] ?? 0)) === 1,
            description: $this->nullableString($record['description'] ?? null),
            expiresAt: $this->nullableString($record['expires_at'] ?? null),
            lastUsedAt: $this->nullableString($record['last_used_at'] ?? null),
            revokedAt: $this->nullableString($record['revoked_at'] ?? null),
            createdAt: $this->nullableString($record['created_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isRecordUsable(array $record): bool
    {
        if (((int) ($record['active'] ?? 0)) !== 1) {
            return false;
        }

        if ($this->nullableString($record['revoked_at'] ?? null) !== null) {
            return false;
        }

        $expiresAt = $this->nullableString($record['expires_at'] ?? null);
        if ($expiresAt === null) {
            return true;
        }

        $expiresTimestamp = strtotime($expiresAt);

        return $expiresTimestamp !== false && $expiresTimestamp > time();
    }

    private function invalidateCacheIfVersionChanged(): void
    {
        $currentMTime = $this->readVersionMTime();

        if ($currentMTime === $this->lastVersionMTime) {
            return;
        }

        if ($currentMTime === null && $this->lastVersionMTime === null) {
            return;
        }

        $this->invalidateAllCache();
        $this->lastVersionMTime = $currentMTime;
    }

    private function bumpVersionFileMTime(): void
    {
        $directory = dirname($this->versionFile);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create API key version directory: %s', $directory));
        }

        if (!is_file($this->versionFile) && file_put_contents($this->versionFile, "\n") === false) {
            throw new RuntimeException(sprintf('Failed to initialize API key version file: %s', $this->versionFile));
        }

        $previousMTime = @filemtime($this->versionFile);
        $targetMTime = max(
            time(),
            $previousMTime === false ? time() : $previousMTime + 1,
        );

        if (!@touch($this->versionFile, $targetMTime)) {
            throw new RuntimeException(sprintf('Failed to update API key version file mtime: %s', $this->versionFile));
        }

        $this->lastVersionMTime = $this->readVersionMTime();
    }

    private function forgetCache(string $kid): void
    {
        $cacheKey = $this->cacheKey($kid);

        if ($this->isApcuEnabled()) {
            apcu_delete($cacheKey);
        }

        unset(self::$cacheFallback[$cacheKey], self::$knownCacheKeys[$cacheKey]);
    }

    private function invalidateAllCache(): void
    {
        foreach (array_keys(self::$knownCacheKeys) as $cacheKey) {
            if ($this->isApcuEnabled()) {
                apcu_delete($cacheKey);
            }

            unset(self::$cacheFallback[$cacheKey], self::$knownCacheKeys[$cacheKey]);
        }
    }

    /**
     * @return array{hit: bool, value: array<string, mixed>|null}
     */
    private function cacheFetch(string $cacheKey): array
    {
        if ($this->isApcuEnabled()) {
            $success = false;
            $value = apcu_fetch($cacheKey, $success);

            if ($success) {
                return [
                    'hit' => true,
                    'value' => is_array($value) ? $value : null,
                ];
            }
        }

        $entry = self::$cacheFallback[$cacheKey] ?? null;
        if (!is_array($entry)) {
            return ['hit' => false, 'value' => null];
        }

        $expiresAt = (float) ($entry['expires_at'] ?? 0.0);
        if ($expiresAt < microtime(true)) {
            unset(self::$cacheFallback[$cacheKey], self::$knownCacheKeys[$cacheKey]);

            return ['hit' => false, 'value' => null];
        }

        return [
            'hit' => true,
            'value' => is_array($entry['value'] ?? null) ? $entry['value'] : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private function cacheStore(string $cacheKey, ?array $value, int $ttlSeconds): void
    {
        self::$knownCacheKeys[$cacheKey] = true;

        if ($this->isApcuEnabled()) {
            apcu_store($cacheKey, $value, $ttlSeconds);
        }

        self::$cacheFallback[$cacheKey] = [
            'expires_at' => microtime(true) + max(1, $ttlSeconds),
            'value' => $value,
        ];
    }

    private function cacheKey(string $kid): string
    {
        return self::CACHE_PREFIX . $this->cacheNamespace . ':' . $kid;
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function readVersionMTime(): ?int
    {
        if (!is_file($this->versionFile)) {
            return null;
        }

        $mtime = @filemtime($this->versionFile);

        return $mtime === false ? null : $mtime;
    }

    private function resolvePepper(?string $pepper): string
    {
        $resolved = trim((string) ($pepper ?? ($_ENV['API_KEY_PEPPER'] ?? getenv('API_KEY_PEPPER') ?: '')));

        if ($resolved === '') {
            throw new RuntimeException('API key pepper is required.');
        }

        return $resolved;
    }

    private function resolveVersionFile(string $versionFile): string
    {
        if ($this->isAbsolutePath($versionFile)) {
            return rtrim($versionFile, DIRECTORY_SEPARATOR);
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($versionFile, DIRECTORY_SEPARATOR);
    }

    private function isApcuEnabled(): bool
    {
        return function_exists('apcu_enabled')
            && function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && apcu_enabled();
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }
}
