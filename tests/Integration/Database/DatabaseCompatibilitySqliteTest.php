<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Core\Database\Migration\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Traits\DatabaseTrait;

#[Group('database-sqlite')]
final class DatabaseCompatibilitySqliteTest extends TestCase
{
    use DatabaseTrait;

    public function testConnection(): void
    {
        $pdo = $this->createConnection('sqlite');

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function testMigrationRunsSuccessfully(): void
    {
        $pdo = $this->createConnection('sqlite');
        $runner = new MigrationRunner($pdo, 'sqlite', $this->getMigrationsDir());

        $result = $runner->run();

        self::assertIsArray($result);
        self::assertArrayHasKey('executed', $result);
        self::assertArrayHasKey('skipped', $result);

        $this->assertTableExists($pdo, 'sqlite', 'api_policy');
        $this->assertTableExists($pdo, 'sqlite', 'api_key');
    }

    public function testRepositoryCrudFlowViaSqlite(): void
    {
        $pdo = $this->createConnection('sqlite');
        (new MigrationRunner($pdo, 'sqlite', $this->getMigrationsDir()))->run();

        $insert = $pdo->prepare(
            "INSERT INTO api_policy (api_id, plugin_id, enabled, visibility, required_scopes, constraints)
             VALUES (:api_id, :plugin_id, :enabled, :visibility, :required_scopes, :constraints)"
        );

        $insert->execute([
            'api_id' => 'compat:sqlite:get',
            'plugin_id' => 'compat-sqlite',
            'enabled' => 1,
            'visibility' => 'private',
            'required_scopes' => '[]',
            'constraints' => '{}',
        ]);

        $found = $pdo->prepare('SELECT * FROM api_policy WHERE api_id = :api_id');
        $found->execute(['api_id' => 'compat:sqlite:get']);

        $row = $found->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('compat-sqlite', $row['plugin_id']);

        $update = $pdo->prepare('UPDATE api_policy SET enabled = :enabled WHERE api_id = :api_id');
        $update->execute([
            'enabled' => 0,
            'api_id' => 'compat:sqlite:get',
        ]);

        $updated = $pdo->prepare('SELECT enabled FROM api_policy WHERE api_id = :api_id');
        $updated->execute(['api_id' => 'compat:sqlite:get']);

        self::assertSame(0, (int) $updated->fetchColumn());

        $delete = $pdo->prepare('DELETE FROM api_policy WHERE api_id = :api_id');
        $delete->execute(['api_id' => 'compat:sqlite:get']);

        $verifyDeleted = $pdo->prepare('SELECT COUNT(*) FROM api_policy WHERE api_id = :api_id');
        $verifyDeleted->execute(['api_id' => 'compat:sqlite:get']);

        self::assertSame(0, (int) $verifyDeleted->fetchColumn());
    }
}
