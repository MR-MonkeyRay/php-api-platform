<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Config;

use App\Core\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testGetSupportsDotNotation(): void
    {
        $config = new Config([
            'app' => [
                'name' => 'php-api-platform',
                'debug' => true,
            ],
        ]);

        self::assertSame('php-api-platform', $config->get('app.name'));
        self::assertTrue($config->get('app.debug'));
    }

    public function testGetReturnsDefaultWhenKeyDoesNotExist(): void
    {
        $config = new Config([]);

        self::assertSame('default', $config->get('missing.value', 'default'));
        self::assertNull($config->get('missing.value'));
        self::assertNull($config->get('missing'));
    }

    public function testHasReturnsExpectedBoolean(): void
    {
        $config = new Config([
            'database' => [
                'type' => 'sqlite',
                'path' => ':memory:',
            ],
        ]);

        self::assertTrue($config->has('database.type'));
        self::assertFalse($config->has('database.username'));
        self::assertFalse($config->has(''));
    }

    public function testGetSupportsDeepNestedAccess(): void
    {
        $config = new Config([
            'database' => [
                'connections' => [
                    'mysql' => [
                        'host' => 'localhost',
                    ],
                ],
            ],
        ]);

        self::assertSame('localhost', $config->get('database.connections.mysql.host'));
    }
}
