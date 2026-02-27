<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Repository;

use App\Core\Repository\ApiKeyRepository;
use App\Core\Repository\RepositoryInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiKeyRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ApiKeyRepository $repository;

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

        $this->repository = new ApiKeyRepository($this->pdo);
    }

    public function testImplementsRepositoryInterface(): void
    {
        self::assertInstanceOf(RepositoryInterface::class, $this->repository);
    }

    public function testCreateApiKey(): void
    {
        $result = $this->repository->create([
            'kid' => 'test1234',
            'secret_hash' => hash('sha256', 'secret'),
            'scopes' => ['read'],
        ]);

        self::assertTrue($result);

        $key = $this->repository->findByKid('test1234');
        self::assertNotNull($key);
        self::assertSame(['read'], $key['scopes']);
    }

    public function testFindByKidNotFound(): void
    {
        self::assertNull($this->repository->findByKid('missing'));
    }

    public function testFindActiveByKid(): void
    {
        $this->repository->create([
            'kid' => 'active',
            'secret_hash' => 'hash-active',
            'active' => 1,
        ]);
        $this->repository->create([
            'kid' => 'inactive',
            'secret_hash' => 'hash-inactive',
            'active' => 0,
        ]);

        self::assertNotNull($this->repository->findActiveByKid('active'));
        self::assertNull($this->repository->findActiveByKid('inactive'));
    }

    public function testFindAllReturnsOrderedRows(): void
    {
        $this->repository->create([
            'kid' => 'k-z',
            'secret_hash' => 'hash-z',
        ]);
        $this->repository->create([
            'kid' => 'k-a',
            'secret_hash' => 'hash-a',
        ]);

        $all = $this->repository->findAll();

        self::assertCount(2, $all);
        self::assertSame('k-a', $all[0]['kid']);
        self::assertSame('k-z', $all[1]['kid']);
    }

    public function testUpdateLastUsed(): void
    {
        $this->repository->create([
            'kid' => 'last-used',
            'secret_hash' => 'hash',
        ]);

        $updated = $this->repository->updateLastUsed('last-used');

        self::assertTrue($updated);

        $key = $this->repository->findByKid('last-used');
        self::assertNotNull($key);
        self::assertNotNull($key['last_used_at']);
    }

    public function testRevoke(): void
    {
        $this->repository->create([
            'kid' => 'to-revoke',
            'secret_hash' => 'hash',
        ]);

        $result = $this->repository->revoke('to-revoke');

        self::assertTrue($result);

        $key = $this->repository->findByKid('to-revoke');
        self::assertNotNull($key);
        self::assertSame(0, $key['active']);
        self::assertNotNull($key['revoked_at']);
    }

    public function testJsonFieldSerialization(): void
    {
        $this->repository->create([
            'kid' => 'json-kid',
            'secret_hash' => 'hash-json',
            'scopes' => ['read', 'write'],
        ]);

        $key = $this->repository->findByKid('json-kid');

        self::assertNotNull($key);
        self::assertSame(['read', 'write'], $key['scopes']);
    }

    public function testCreateUsesPreparedStatementsAgainstSqlInjectionPayload(): void
    {
        $kid = "kid'; DROP TABLE api_key; --";

        $this->repository->create([
            'kid' => $kid,
            'secret_hash' => 'safe-hash',
            'scopes' => ['read'],
        ]);

        $key = $this->repository->findByKid($kid);
        self::assertNotNull($key);

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='api_key'")?->fetchColumn();
        self::assertSame('api_key', $table);
    }
}
