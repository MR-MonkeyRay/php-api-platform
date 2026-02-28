<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;

final class DatabaseOperationsGuideTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = dirname(__DIR__, 2);
    }

    public function testGuideExists(): void
    {
        self::assertFileExists($this->projectDir . '/docs/guides/database-operations.md');
    }

    public function testCoversAllDatabaseTypes(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/database-operations.md');

        self::assertStringContainsString('SQLite', $content);
        self::assertStringContainsString('MySQL', $content);
        self::assertStringContainsString('PostgreSQL', $content);
    }

    public function testDsnExamplesIncluded(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/database-operations.md');

        self::assertStringContainsString('sqlite:', $content);
        self::assertStringContainsString('mysql:host=', $content);
        self::assertStringContainsString('pgsql:host=', $content);
    }

    public function testMigrationCommandsDocumented(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/database-operations.md');

        self::assertStringContainsString('bin/migrate', $content);
        self::assertStringContainsString('docker compose exec app php bin/migrate', $content);
    }
}
