<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Database;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    public function testCreateSqliteInMemoryConnection(): void
    {
        $config = new Config([
            'database' => [
                'type' => 'sqlite',
                'path' => ':memory:',
            ],
        ]);

        $factory = new ConnectionFactory($config);
        $pdo = $factory->create();

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function testCreateReturnsSingletonConnection(): void
    {
        $factory = new ConnectionFactory([
            'database' => [
                'type' => 'sqlite',
                'path' => ':memory:',
            ],
        ]);

        $first = $factory->create();
        $second = $factory->create();

        self::assertSame($first, $second);
    }

    public function testCreateThrowsForUnsupportedDriver(): void
    {
        $factory = new ConnectionFactory([
            'database' => [
                'type' => 'oracle',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $factory->create();
    }

    public function testCreateSetsExpectedPdoAttributes(): void
    {
        $factory = new ConnectionFactory([
            'database' => [
                'type' => 'sqlite',
                'path' => ':memory:',
            ],
        ]);

        $pdo = $factory->create();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));

        try {
            $emulatePrepares = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
            self::assertFalse($emulatePrepares);
        } catch (PDOException $exception) {
            self::assertStringContainsString('does not support', strtolower($exception->getMessage()));
        }
    }

}
