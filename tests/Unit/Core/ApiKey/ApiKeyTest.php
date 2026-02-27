<?php

declare(strict_types=1);

namespace Tests\Unit\Core\ApiKey;

use App\Core\ApiKey\ApiKey;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testDtoStoresFields(): void
    {
        $dto = new ApiKey(
            kid: 'kid-001',
            scopes: ['read', 'write'],
            active: true,
            description: 'test key',
            expiresAt: '2030-01-01 00:00:00',
            lastUsedAt: null,
            revokedAt: null,
            createdAt: '2026-01-01 00:00:00',
        );

        self::assertSame('kid-001', $dto->kid);
        self::assertSame(['read', 'write'], $dto->scopes);
        self::assertTrue($dto->active);
        self::assertSame('test key', $dto->description);
        self::assertSame('2030-01-01 00:00:00', $dto->expiresAt);
        self::assertNull($dto->lastUsedAt);
        self::assertNull($dto->revokedAt);
        self::assertSame('2026-01-01 00:00:00', $dto->createdAt);
    }
}
