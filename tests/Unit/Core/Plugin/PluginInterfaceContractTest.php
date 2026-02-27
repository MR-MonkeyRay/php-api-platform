<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin;

use App\Core\Plugin\ApiDefinition;
use App\Core\Plugin\PluginInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PluginInterfaceContractTest extends TestCase
{
    public function testPluginInterfaceDefinesExpectedMethods(): void
    {
        $reflection = new ReflectionClass(PluginInterface::class);

        self::assertTrue($reflection->hasMethod('getId'));
        self::assertTrue($reflection->hasMethod('getName'));
        self::assertTrue($reflection->hasMethod('getVersion'));
        self::assertTrue($reflection->hasMethod('routes'));
        self::assertTrue($reflection->hasMethod('apis'));

        self::assertSame('array', $reflection->getMethod('apis')->getReturnType()?->__toString());
    }

    public function testAnonymousPluginImplementsInterfaceAndReturnsApiDefinitions(): void
    {
        $plugin = new class implements PluginInterface {
            public function getId(): string
            {
                return 'sample-plugin';
            }

            public function getName(): string
            {
                return 'Sample Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function routes(\Slim\App $app): void
            {
                // no-op for contract test
            }

            public function apis(): array
            {
                return [
                    new ApiDefinition('sample:status:get', 'public', ['read']),
                ];
            }
        };

        self::assertSame('sample-plugin', $plugin->getId());
        self::assertSame('Sample Plugin', $plugin->getName());
        self::assertSame('1.0.0', $plugin->getVersion());
        self::assertCount(1, $plugin->apis());
        self::assertInstanceOf(ApiDefinition::class, $plugin->apis()[0]);
    }
}
