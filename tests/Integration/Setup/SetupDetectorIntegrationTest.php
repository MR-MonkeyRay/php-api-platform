<?php

declare(strict_types=1);

namespace Tests\Integration\Setup;

use App\Core\Setup\SetupDetector;
use PHPUnit\Framework\TestCase;

final class SetupDetectorIntegrationTest extends TestCase
{
    private string $absoluteVarDir;
    private string $relativeVarDir;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->absoluteVarDir = sys_get_temp_dir() . '/setup-detector-integration-' . $suffix;
        $this->relativeVarDir = 'var/setup-detector-integration-' . $suffix;
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->absoluteVarDir);
        $this->deleteDirectory(dirname(__DIR__, 3) . '/' . $this->relativeVarDir);

        parent::tearDown();
    }

    public function testTokenLifecycleWorksAcrossValidationAndConsume(): void
    {
        $detector = new SetupDetector($this->absoluteVarDir);
        $token = $detector->generateSetupToken();

        self::assertTrue($detector->validateSetupToken($token));
        self::assertTrue($detector->consumeSetupToken($token));
        self::assertFalse($detector->validateSetupToken($token));
    }

    public function testGeneratingNewTokenInvalidatesPreviousToken(): void
    {
        $detector = new SetupDetector($this->absoluteVarDir);
        $firstToken = $detector->generateSetupToken();
        $secondToken = $detector->generateSetupToken();

        self::assertFalse($detector->validateSetupToken($firstToken));
        self::assertTrue($detector->validateSetupToken($secondToken));
    }

    public function testRelativeVarDirectoryResolvesFromProjectRoot(): void
    {
        $detector = new SetupDetector($this->relativeVarDir);
        $token = $detector->generateSetupToken();

        $tokenFile = dirname(__DIR__, 3) . '/' . $this->relativeVarDir . '/.setup-token';

        self::assertSame(64, strlen($token));
        self::assertFileExists($tokenFile);
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
