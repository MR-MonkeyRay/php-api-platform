<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use PHPUnit\Framework\TestCase;

final class DatabaseCompatibilityTest extends TestCase
{
    public function testCompatibilitySuitesAreDeclared(): void
    {
        self::assertTrue(class_exists(DatabaseCompatibilitySqliteTest::class));
        self::assertTrue(class_exists(DatabaseCompatibilityMysqlTest::class));
        self::assertTrue(class_exists(DatabaseCompatibilityPgsqlTest::class));
    }
}
