<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Setup;

use App\Core\Setup\SetupDetector;
use PHPUnit\Framework\TestCase;

final class SetupDetectorTest extends TestCase
{
    private string $varDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->varDir = sys_get_temp_dir() . '/setup-detector-unit-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->varDir);

        parent::tearDown();
    }

    public function testIsInstalledReturnsFalseInitially(): void
    {
        $detector = new SetupDetector($this->varDir);

        self::assertFalse($detector->isInstalled());
    }

    public function testMarkInstalledCreatesMarkerFile(): void
    {
        $detector = new SetupDetector($this->varDir);
        $detector->markInstalled();

        self::assertTrue($detector->isInstalled());
        self::assertFileExists($this->varDir . '/.installed');
    }

    public function testGenerateSetupTokenPersistsTokenWithTtl(): void
    {
        $detector = new SetupDetector($this->varDir);
        $token = $detector->generateSetupToken();

        self::assertSame(64, strlen($token));
        self::assertFileExists($this->varDir . '/.setup-token');

        $payload = json_decode(
            (string) file_get_contents($this->varDir . '/.setup-token'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame($token, $payload['token']);
        self::assertGreaterThanOrEqual(time() + 3590, (int) $payload['expires_at']);
    }

    public function testValidateSetupTokenRejectsInvalidToken(): void
    {
        $detector = new SetupDetector($this->varDir);
        $detector->generateSetupToken();

        self::assertFalse($detector->validateSetupToken('invalid-token'));
    }

    public function testValidateSetupTokenRejectsExpiredToken(): void
    {
        $detector = new SetupDetector($this->varDir, tokenTtlSeconds: 1);
        $token = $detector->generateSetupToken();

        $payload = json_decode(
            (string) file_get_contents($this->varDir . '/.setup-token'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $payload['expires_at'] = time() - 1;
        file_put_contents(
            $this->varDir . '/.setup-token',
            (string) json_encode($payload, JSON_THROW_ON_ERROR),
        );

        self::assertFalse($detector->validateSetupToken($token));
        self::assertFileDoesNotExist($this->varDir . '/.setup-token');
    }

    public function testConsumeSetupTokenIsOneTime(): void
    {
        $detector = new SetupDetector($this->varDir);
        $token = $detector->generateSetupToken();

        self::assertTrue($detector->consumeSetupToken($token));
        self::assertFileDoesNotExist($this->varDir . '/.setup-token');
        self::assertFalse($detector->consumeSetupToken($token));
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
