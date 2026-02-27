<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Core\Database\Migration\MigrationRunner;
use App\Core\Repository\ApiPolicyRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Traits\DatabaseTrait;

abstract class DatabaseTestCase extends TestCase
{
    use DatabaseTrait;

    protected PDO $pdo;

    abstract protected function getDatabaseType(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $databaseType = $this->getDatabaseType();
        $this->ensureDriverEnabled($databaseType);

        $this->pdo = $this->createConnection($databaseType);
        $this->runMigrations($this->pdo, $databaseType);
        $this->resetCoreTables($this->pdo, $databaseType);
    }

    public function testConnection(): void
    {
        self::assertInstanceOf(PDO::class, $this->pdo);
        self::assertSame($this->getDatabaseType(), $this->normalizeDriverName($this->pdo));
    }

    public function testMigrationRunsSuccessfully(): void
    {
        $databaseType = $this->getDatabaseType();

        $this->assertTableExists($this->pdo, $databaseType, 'api_policy');
        $this->assertTableExists($this->pdo, $databaseType, 'api_key');

        $runner = new MigrationRunner($this->pdo, $databaseType, $this->getMigrationsDir());
        $result = $runner->run();

        self::assertArrayHasKey('executed', $result);
        self::assertArrayHasKey('skipped', $result);
        self::assertSame([], $result['executed']);
    }

    public function testRepositoryCrud(): void
    {
        $repository = new ApiPolicyRepository($this->pdo);

        self::assertTrue($repository->upsert([
            'api_id' => 'compat:api:get',
            'plugin_id' => 'compat-plugin',
            'required_scopes' => ['read'],
            'constraints' => ['rate_limit' => 100],
        ]));

        $fetched = $repository->findByApiId('compat:api:get');
        self::assertNotNull($fetched);
        self::assertSame('compat-plugin', $fetched['plugin_id']);
        self::assertSame(['read'], $fetched['required_scopes']);

        self::assertTrue($repository->upsert([
            'api_id' => 'compat:api:get',
            'plugin_id' => 'compat-plugin',
            'enabled' => 0,
        ]));

        $updated = $repository->findByApiId('compat:api:get');
        self::assertNotNull($updated);
        self::assertSame(0, $updated['enabled']);

        self::assertTrue($repository->delete('compat:api:get'));
        self::assertNull($repository->findByApiId('compat:api:get'));
    }

    private function normalizeDriverName(PDO $pdo): string
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        return match ($driver) {
            'postgres', 'postgresql' => 'pgsql',
            'sqlite', 'mysql', 'pgsql' => $driver,
            default => throw new RuntimeException(sprintf('Unexpected PDO driver: %s', $driver)),
        };
    }
}
