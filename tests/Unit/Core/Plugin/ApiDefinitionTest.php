<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin;

use App\Core\Plugin\ApiDefinition;
use App\Core\Plugin\InvalidPluginException;
use PHPUnit\Framework\TestCase;

final class ApiDefinitionTest extends TestCase
{
    public function testApiDefinitionIsImmutable(): void
    {
        $definition = new ApiDefinition(
            apiId: 'test:api:get',
            visibilityDefault: 'public',
            requiredScopesDefault: ['read']
        );

        self::assertSame('test:api:get', $definition->apiId);
        self::assertSame('public', $definition->visibilityDefault);
        self::assertSame(['read'], $definition->requiredScopesDefault);
    }

    public function testRejectsInvalidVisibilityDefault(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('visibilityDefault');

        new ApiDefinition(
            apiId: 'test:api:get',
            visibilityDefault: 'internal',
            requiredScopesDefault: ['read']
        );
    }

    public function testRejectsEmptyApiId(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('apiId');

        new ApiDefinition(
            apiId: '',
            visibilityDefault: 'public',
        );
    }

    public function testRejectsEmptyScopeInRequiredScopesDefault(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('requiredScopesDefault');

        new ApiDefinition(
            apiId: 'test:api:get',
            visibilityDefault: 'public',
            requiredScopesDefault: ['']
        );
    }
}
