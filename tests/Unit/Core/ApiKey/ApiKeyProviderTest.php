<?php

declare(strict_types=1);

namespace Tests\Unit\Core\ApiKey;

use App\Core\ApiKey\ApiKey;
use App\Core\ApiKey\ApiKeyProvider;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiKeyProviderTest extends TestCase
{
    private PDO $pdo;
    private string $versionFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE api_key (
                kid TEXT PRIMARY KEY,
                secret_hash TEXT NOT NULL,
                scopes TEXT NOT NULL DEFAULT '[]',
                active INTEGER NOT NULL DEFAULT 1,
                description TEXT,
                expires_at TEXT,
                last_used_at TEXT,
                revoked_at TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL
        );

        $this->versionFile = sys_get_temp_dir() . '/apikey-version-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->versionFile)) {
            @unlink($this->versionFile);
        }

        parent::tearDown();
    }

    public function testValidateValidKey(): void
    {
        $pepper = 'test-pepper';
        $secret = 'test-secret';
        $secretHash = hash_hmac('sha256', $secret, $pepper);

        $this->createApiKey([
            'kid' => 'test1234',
            'secret_hash' => $secretHash,
            'active' => 1,
            'scopes' => json_encode(['read'], JSON_THROW_ON_ERROR),
        ]);

        $provider = new ApiKeyProvider($this->pdo, $pepper, $this->versionFile);
        $result = $provider->validate('test1234', $secret);

        self::assertInstanceOf(ApiKey::class, $result);
        self::assertSame('test1234', $result->kid);
        self::assertSame(['read'], $result->scopes);
    }

    public function testValidateInvalidSecret(): void
    {
        $this->createApiKey([
            'kid' => 'test1234',
            'secret_hash' => hash_hmac('sha256', 'correct', 'pepper'),
            'active' => 1,
        ]);

        $provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);
        $result = $provider->validate('test1234', 'wrong');

        self::assertNull($result);
    }

    public function testValidateExpiredKey(): void
    {
        $this->createApiKey([
            'kid' => 'test1234',
            'secret_hash' => hash_hmac('sha256', 'any', 'pepper'),
            'active' => 1,
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        $provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);
        $result = $provider->validate('test1234', 'any');

        self::assertNull($result);
    }

    public function testValidateRevokedKey(): void
    {
        $this->createApiKey([
            'kid' => 'test1234',
            'secret_hash' => hash_hmac('sha256', 'any', 'pepper'),
            'active' => 0,
            'revoked_at' => '2026-01-01 00:00:00',
        ]);

        $provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);
        $result = $provider->validate('test1234', 'any');

        self::assertNull($result);
    }

    public function testCacheUsedWhenDatabaseRowRemoved(): void
    {
        $this->createApiKey([
            'kid' => 'test1234',
            'secret_hash' => hash_hmac('sha256', 'secret', 'pepper'),
            'active' => 1,
        ]);

        $provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);

        $first = $provider->validate('test1234', 'secret');
        self::assertNotNull($first);

        $this->pdo->exec("DELETE FROM api_key WHERE kid = 'test1234'");

        $second = $provider->validate('test1234', 'secret');
        self::assertNotNull($second);
    }

    public function testRevokeClearsCacheAndInvalidatesImmediately(): void
    {
        $this->createApiKey([
            'kid' => 'test1234',
            'secret_hash' => hash_hmac('sha256', 'secret', 'pepper'),
            'active' => 1,
        ]);

        $provider = new ApiKeyProvider($this->pdo, 'pepper', $this->versionFile);
        self::assertNotNull($provider->validate('test1234', 'secret'));

        $provider->revoke('test1234');

        $result = $provider->validate('test1234', 'secret');
        self::assertNull($result);
    }

    public function testThrowsWhenPepperMissing(): void
    {
        $previous = $_ENV['API_KEY_PEPPER'] ?? null;
        $previousEnv = getenv('API_KEY_PEPPER');
        unset($_ENV['API_KEY_PEPPER']);
        putenv('API_KEY_PEPPER');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pepper');

        try {
            new ApiKeyProvider($this->pdo, null, $this->versionFile);
        } finally {
            if ($previous !== null) {
                $_ENV['API_KEY_PEPPER'] = $previous;
            } else {
                unset($_ENV['API_KEY_PEPPER']);
            }

            if ($previousEnv !== false) {
                putenv(sprintf('API_KEY_PEPPER=%s', $previousEnv));
            } else {
                putenv('API_KEY_PEPPER');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createApiKey(array $data): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO api_key (
                kid,
                secret_hash,
                scopes,
                active,
                description,
                expires_at,
                last_used_at,
                revoked_at
            ) VALUES (
                :kid,
                :secret_hash,
                :scopes,
                :active,
                :description,
                :expires_at,
                :last_used_at,
                :revoked_at
            )
            SQL
        );

        $statement->execute([
            'kid' => (string) ($data['kid'] ?? ''),
            'secret_hash' => (string) ($data['secret_hash'] ?? ''),
            'scopes' => (string) ($data['scopes'] ?? '[]'),
            'active' => (int) ($data['active'] ?? 1),
            'description' => $data['description'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'last_used_at' => $data['last_used_at'] ?? null,
            'revoked_at' => $data['revoked_at'] ?? null,
        ]);
    }
}
