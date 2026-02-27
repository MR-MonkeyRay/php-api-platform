<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin;

use App\Core\Plugin\ApiDefinition;
use PHPUnit\Framework\TestCase;

final class PluginInterfaceTest extends TestCase
{
    public function testApiDefinitionIsImmutable(): void
    {
        $definition = new ApiDefinition(
            apiId: 'test:api:get',
            visibilityDefault: 'public',
            requiredScopesDefault: ['read'],
        );

        self::assertSame('test:api:get', $definition->apiId);
        self::assertSame('public', $definition->visibilityDefault);
        self::assertSame(['read'], $definition->requiredScopesDefault);
    }
}
