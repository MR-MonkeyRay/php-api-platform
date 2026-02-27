<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Traits\DatabaseTrait;

final class CoreTablesTest extends TestCase
{
    use DatabaseTrait;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = $this->createConnection('sqlite');
        $this->runMigrations($this->pdo, 'sqlite');
    }

    public function testApiPolicyTableStructure(): void
    {
        $columns = $this->getTableColumns('api_policy');
        $expectedColumns = [
            'api_id',
            'plugin_id',
            'enabled',
            'visibility',
            'required_scopes',
            'constraints',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedColumns as $column) {
            self::assertContains($column, $columns);
        }

        $tableInfo = $this->pdo->query('PRAGMA table_info(api_policy)')->fetchAll(PDO::FETCH_ASSOC);

        $primaryKeyColumns = array_values(
            array_filter(
                $tableInfo,
                static fn (array $column): bool => (int) ($column['pk'] ?? 0) === 1,
            ),
        );

        self::assertCount(1, $primaryKeyColumns);
        self::assertSame('api_id', $primaryKeyColumns[0]['name']);
    }

    public function testApiKeyTableStructure(): void
    {
        $columns = $this->getTableColumns('api_key');
        $expectedColumns = [
            'kid',
            'secret_hash',
            'scopes',
            'active',
            'description',
            'expires_at',
            'last_used_at',
            'revoked_at',
            'created_at',
        ];

        foreach ($expectedColumns as $column) {
            self::assertContains($column, $columns);
        }
    }

    public function testIndexesCreated(): void
    {
        $policyIndexes = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='api_policy'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $keyIndexes = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='api_key'")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('idx_api_policy_plugin', $policyIndexes);
        self::assertContains('idx_api_key_active', $keyIndexes);
    }

    public function testInsertAndSelect(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO api_policy (api_id, plugin_id) VALUES (:api_id, :plugin_id)'
        );

        $statement->execute([
            'api_id' => 'test:api:get',
            'plugin_id' => 'test',
        ]);

        $query = $this->pdo->prepare('SELECT * FROM api_policy WHERE api_id = :api_id');
        $query->execute(['api_id' => 'test:api:get']);

        $row = $query->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('test:api:get', $row['api_id']);
        self::assertSame(1, (int) $row['enabled']);
        self::assertSame('private', $row['visibility']);
    }

    /**
     * @return list<string>
     */
    private function getTableColumns(string $table): array
    {
        if (preg_match('/^[a-z_]+$/', $table) !== 1) {
            throw new RuntimeException(sprintf('Invalid table name: %s', $table));
        }

        $statement = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table));
        $columns = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $column): string => (string) $column['name'], $columns));
    }
}
