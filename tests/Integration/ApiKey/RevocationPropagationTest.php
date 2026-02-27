<?php

declare(strict_types=1);

namespace Tests\Integration\ApiKey;

use App\Core\ApiKey\ApiKeyProvider;
use PDO;
use PHPUnit\Framework\TestCase;

final class RevocationPropagationTest extends TestCase
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

        $this->versionFile = sys_get_temp_dir() . '/apikey-propagation-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->versionFile)) {
            @unlink($this->versionFile);
        }

        parent::tearDown();
    }

    public function testRevokeImmediatelyInvalidates(): void
    {
        $pepper = 'pepper';
        $secret = 'secret';

        $this->createApiKey([
            'kid' => 'testkey',
            'secret_hash' => hash_hmac('sha256', $secret, $pepper),
            'active' => 1,
        ]);

        $provider = new ApiKeyProvider($this->pdo, $pepper, $this->versionFile);

        self::assertNotNull($provider->validate('testkey', $secret));

        $provider->revoke('testkey');

        self::assertNull($provider->validate('testkey', $secret));
    }

    public function testMtimeUpdateOnRevoke(): void
    {
        $pepper = 'pepper';

        $this->createApiKey([
            'kid' => 'testkey',
            'secret_hash' => hash_hmac('sha256', 'secret', $pepper),
            'active' => 1,
        ]);

        $provider = new ApiKeyProvider($this->pdo, $pepper, $this->versionFile);

        file_put_contents($this->versionFile, "\n");
        touch($this->versionFile, time() - 5);
        $oldMtime = filemtime($this->versionFile);

        $provider->revoke('testkey');

        $newMtime = filemtime($this->versionFile);
        self::assertIsInt($oldMtime);
        self::assertIsInt($newMtime);
        self::assertGreaterThan($oldMtime, $newMtime);
    }

    public function testOtherKeysUnaffectedWhenOneKeyRevoked(): void
    {
        $pepper = 'pepper';

        $this->createApiKey([
            'kid' => 'key1',
            'secret_hash' => hash_hmac('sha256', 's1', $pepper),
            'active' => 1,
        ]);
        $this->createApiKey([
            'kid' => 'key2',
            'secret_hash' => hash_hmac('sha256', 's2', $pepper),
            'active' => 1,
        ]);

        $provider = new ApiKeyProvider($this->pdo, $pepper, $this->versionFile);

        self::assertNotNull($provider->validate('key1', 's1'));
        self::assertNotNull($provider->validate('key2', 's2'));

        $provider->revoke('key1');

        self::assertNull($provider->validate('key1', 's1'));
        self::assertNotNull($provider->validate('key2', 's2'));
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
