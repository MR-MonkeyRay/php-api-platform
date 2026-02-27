<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Core\Database\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Traits\DatabaseTrait;

#[Group('database-pgsql')]
final class DatabaseCompatibilityPgsqlTest extends TestCase
{
    use DatabaseTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureDriverEnabled('pgsql');
    }

    public function testConnection(): void
    {
        $pdo = $this->createConnection('pgsql');

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame('pgsql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function testMigrationRunsSuccessfully(): void
    {
        $pdo = $this->createConnection('pgsql');
        $runner = new MigrationRunner($pdo, 'pgsql', $this->getMigrationsDir());

        $result = $runner->run();

        self::assertIsArray($result);
        self::assertArrayHasKey('executed', $result);
        self::assertArrayHasKey('skipped', $result);

        $this->assertTableExists($pdo, 'pgsql', 'api_policy');
        $this->assertTableExists($pdo, 'pgsql', 'api_key');
    }

    public function testRepositoryCrudFlowViaPgsql(): void
    {
        $pdo = $this->createConnection('pgsql');
        (new MigrationRunner($pdo, 'pgsql', $this->getMigrationsDir()))->run();

        $cleanup = $pdo->prepare('DELETE FROM api_policy WHERE api_id = :api_id');
        $cleanup->execute(['api_id' => 'compat:pgsql:get']);

        $insert = $pdo->prepare(
            'INSERT INTO api_policy (api_id, plugin_id, enabled, visibility, required_scopes, "constraints")
             VALUES (:api_id, :plugin_id, :enabled, :visibility, :required_scopes, :constraints)'
        );

        $insert->execute([
            'api_id' => 'compat:pgsql:get',
            'plugin_id' => 'compat-pgsql',
            'enabled' => true,
            'visibility' => 'private',
            'required_scopes' => '[]',
            'constraints' => '{}',
        ]);

        $found = $pdo->prepare('SELECT * FROM api_policy WHERE api_id = :api_id');
        $found->execute(['api_id' => 'compat:pgsql:get']);

        $row = $found->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('compat-pgsql', $row['plugin_id']);

        $update = $pdo->prepare('UPDATE api_policy SET enabled = :enabled WHERE api_id = :api_id');
        $update->execute([
            'enabled' => 0,
            'api_id' => 'compat:pgsql:get',
        ]);

        $updated = $pdo->prepare('SELECT enabled FROM api_policy WHERE api_id = :api_id');
        $updated->execute(['api_id' => 'compat:pgsql:get']);

        self::assertFalse((bool) $updated->fetchColumn());

        $delete = $pdo->prepare('DELETE FROM api_policy WHERE api_id = :api_id');
        $delete->execute(['api_id' => 'compat:pgsql:get']);

        $verifyDeleted = $pdo->prepare('SELECT COUNT(*) FROM api_policy WHERE api_id = :api_id');
        $verifyDeleted->execute(['api_id' => 'compat:pgsql:get']);

        self::assertSame(0, (int) $verifyDeleted->fetchColumn());
    }
}
