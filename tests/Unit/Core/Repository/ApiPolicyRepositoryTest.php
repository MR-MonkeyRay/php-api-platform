<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Repository;

use App\Core\Repository\ApiPolicyRepository;
use App\Core\Repository\RepositoryInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiPolicyRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ApiPolicyRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE api_policy (
                api_id TEXT PRIMARY KEY,
                plugin_id TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                visibility TEXT NOT NULL DEFAULT 'private',
                required_scopes TEXT NOT NULL DEFAULT '[]',
                constraints TEXT NOT NULL DEFAULT '{}',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
            SQL
        );

        $this->repository = new ApiPolicyRepository($this->pdo);
    }

    public function testImplementsRepositoryInterface(): void
    {
        self::assertInstanceOf(RepositoryInterface::class, $this->repository);
    }

    public function testFindByApiId(): void
    {
        $this->pdo->exec("INSERT INTO api_policy (api_id, plugin_id) VALUES ('test:api:get', 'test')");

        $policy = $this->repository->findByApiId('test:api:get');

        self::assertNotNull($policy);
        self::assertSame('test:api:get', $policy['api_id']);
    }

    public function testFindByApiIdNotFound(): void
    {
        $policy = $this->repository->findByApiId('nonexistent');

        self::assertNull($policy);
    }

    public function testUpsertCreatesNew(): void
    {
        $result = $this->repository->upsert([
            'api_id' => 'new:api:get',
            'plugin_id' => 'new',
            'visibility' => 'public',
        ]);

        self::assertTrue($result);

        $policy = $this->repository->findByApiId('new:api:get');
        self::assertNotNull($policy);
        self::assertSame('public', $policy['visibility']);
    }

    public function testUpsertUpdatesExisting(): void
    {
        $this->pdo->exec("INSERT INTO api_policy (api_id, plugin_id, enabled) VALUES ('test:api:get', 'test', 1)");

        $result = $this->repository->upsert([
            'api_id' => 'test:api:get',
            'enabled' => 0,
        ]);

        self::assertTrue($result);

        $policy = $this->repository->findByApiId('test:api:get');
        self::assertNotNull($policy);
        self::assertSame(0, $policy['enabled']);
    }

    public function testFindByPluginIdReturnsMatchingRecordsOnly(): void
    {
        $this->repository->upsert([
            'api_id' => 'alpha:get',
            'plugin_id' => 'alpha',
            'visibility' => 'public',
        ]);
        $this->repository->upsert([
            'api_id' => 'alpha:list',
            'plugin_id' => 'alpha',
        ]);
        $this->repository->upsert([
            'api_id' => 'beta:get',
            'plugin_id' => 'beta',
        ]);

        $policies = $this->repository->findByPluginId('alpha');

        self::assertCount(2, $policies);
        self::assertSame('alpha:get', $policies[0]['api_id']);
        self::assertSame('alpha:list', $policies[1]['api_id']);
    }

    public function testFindAllReturnsRecordsSortedByApiId(): void
    {
        $this->repository->upsert([
            'api_id' => 'zeta:get',
            'plugin_id' => 'zeta',
        ]);
        $this->repository->upsert([
            'api_id' => 'alpha:get',
            'plugin_id' => 'alpha',
        ]);

        $all = $this->repository->findAll();

        self::assertCount(2, $all);
        self::assertSame('alpha:get', $all[0]['api_id']);
        self::assertSame('zeta:get', $all[1]['api_id']);
    }

    public function testDeleteRemovesRecord(): void
    {
        $this->repository->upsert([
            'api_id' => 'delete:me',
            'plugin_id' => 'test',
        ]);

        $deleted = $this->repository->delete('delete:me');

        self::assertTrue($deleted);
        self::assertNull($this->repository->findByApiId('delete:me'));
    }

    public function testJsonFieldSerialization(): void
    {
        $this->repository->upsert([
            'api_id' => 'test:api',
            'plugin_id' => 'test',
            'required_scopes' => ['read', 'write'],
            'constraints' => ['ip_whitelist' => ['10.0.0.1']],
        ]);

        $policy = $this->repository->findByApiId('test:api');

        self::assertNotNull($policy);
        self::assertSame(['read', 'write'], $policy['required_scopes']);
        self::assertSame(['ip_whitelist' => ['10.0.0.1']], $policy['constraints']);
    }

    public function testUpsertUsesPreparedStatementsAgainstSqlInjectionPayload(): void
    {
        $apiId = "evil'; DROP TABLE api_policy; --";

        $this->repository->upsert([
            'api_id' => $apiId,
            'plugin_id' => 'safe-plugin',
        ]);

        $policy = $this->repository->findByApiId($apiId);
        self::assertNotNull($policy);
        self::assertSame('safe-plugin', $policy['plugin_id']);

        $table = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='api_policy'")?->fetchColumn();
        self::assertSame('api_policy', $table);
    }
}
