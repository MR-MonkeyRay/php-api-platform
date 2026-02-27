<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PhpUnitWorksTest extends TestCase
{
    public function testPhpUnitRuns(): void
    {
        self::assertTrue(true, 'PHPUnit should be working');
    }
}
