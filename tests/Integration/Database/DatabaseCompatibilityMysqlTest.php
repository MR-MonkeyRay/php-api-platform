<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Core\Database\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Traits\DatabaseTrait;

#[Group('database-mysql')]
final class DatabaseCompatibilityMysqlTest extends TestCase
{
    use DatabaseTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureDriverEnabled('mysql');
    }

    public function testConnection(): void
    {
        $pdo = $this->createConnection('mysql');

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function testMigrationRunsSuccessfully(): void
    {
        $pdo = $this->createConnection('mysql');
        $runner = new MigrationRunner($pdo, 'mysql', $this->getMigrationsDir());

        $result = $runner->run();

        self::assertIsArray($result);
        self::assertArrayHasKey('executed', $result);
        self::assertArrayHasKey('skipped', $result);

        $this->assertTableExists($pdo, 'mysql', 'api_policy');
        $this->assertTableExists($pdo, 'mysql', 'api_key');
    }

    public function testRepositoryCrudFlowViaMysql(): void
    {
        $pdo = $this->createConnection('mysql');
        (new MigrationRunner($pdo, 'mysql', $this->getMigrationsDir()))->run();

        $cleanup = $pdo->prepare('DELETE FROM api_policy WHERE api_id = :api_id');
        $cleanup->execute(['api_id' => 'compat:mysql:get']);

        $insert = $pdo->prepare(
            "INSERT INTO api_policy (api_id, plugin_id, enabled, visibility, required_scopes, `constraints`)
             VALUES (:api_id, :plugin_id, :enabled, :visibility, :required_scopes, :constraints)"
        );

        $insert->execute([
            'api_id' => 'compat:mysql:get',
            'plugin_id' => 'compat-mysql',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => '[]',
            'constraints' => '{}',
        ]);

        $found = $pdo->prepare('SELECT * FROM api_policy WHERE api_id = :api_id');
        $found->execute(['api_id' => 'compat:mysql:get']);

        $row = $found->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('compat-mysql', $row['plugin_id']);

        $update = $pdo->prepare('UPDATE api_policy SET enabled = :enabled WHERE api_id = :api_id');
        $update->execute([
            'enabled' => 0,
            'api_id' => 'compat:mysql:get',
        ]);

        $updated = $pdo->prepare('SELECT enabled FROM api_policy WHERE api_id = :api_id');
        $updated->execute(['api_id' => 'compat:mysql:get']);

        self::assertSame(0, (int) $updated->fetchColumn());

        $delete = $pdo->prepare('DELETE FROM api_policy WHERE api_id = :api_id');
        $delete->execute(['api_id' => 'compat:mysql:get']);

        $verifyDeleted = $pdo->prepare('SELECT COUNT(*) FROM api_policy WHERE api_id = :api_id');
        $verifyDeleted->execute(['api_id' => 'compat:mysql:get']);

        self::assertSame(0, (int) $verifyDeleted->fetchColumn());
    }
}
