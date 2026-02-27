<?php

declare(strict_types=1);

namespace Tests\Unit\Core\ApiKey;

use App\Core\ApiKey\ApiKeyGenerator;
use PHPUnit\Framework\TestCase;

final class ApiKeyGeneratorTest extends TestCase
{
    public function testGenerateReturnsExpectedShapes(): void
    {
        $generator = new ApiKeyGenerator();

        $generated = $generator->generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $generated['kid']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $generated['secret']);
        self::assertSame($generated['kid'] . '.' . $generated['secret'], $generated['full_key']);
        self::assertSame(60, strlen($generated['full_key']));
    }

    public function testGenerateKidAndSecretUseExpectedLengths(): void
    {
        $generator = new ApiKeyGenerator();

        self::assertSame(16, strlen($generator->generateKid()));
        self::assertSame(43, strlen($generator->generateSecret()));
    }
}
