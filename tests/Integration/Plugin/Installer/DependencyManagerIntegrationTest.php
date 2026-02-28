<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin\Installer;

use App\Core\Plugin\Installer\DependencyManager;
use PHPUnit\Framework\TestCase;

final class DependencyManagerIntegrationTest extends TestCase
{
    private string $projectDir;
    private string $composerJson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir() . '/dependency-manager-integration-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->projectDir, 0755, true) || is_dir($this->projectDir));

        $this->composerJson = $this->projectDir . '/composer.json';
        file_put_contents(
            $this->composerJson,
            (string) json_encode([
                'name' => 'integration/dependency-manager',
                'description' => 'fixture',
                'require' => [
                    'php' => '^8.4',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->composerJson)) {
            @unlink($this->composerJson);
        }

        if (is_dir($this->projectDir)) {
            @rmdir($this->projectDir);
        }

        parent::tearDown();
    }

    public function testAddPathRepositoryWritesPathRepoEntry(): void
    {
        $manager = new DependencyManager($this->composerJson);

        $result = $manager->addPathRepository('plugins/sample-plugin');

        self::assertTrue($result['changed']);

        $composer = json_decode((string) file_get_contents($this->composerJson), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('repositories', $composer);
        self::assertSame('path', $composer['repositories'][0]['type']);
        self::assertSame('plugins/sample-plugin', $composer['repositories'][0]['url']);
    }

    public function testRequireAndUpdateCommandsExecuteWithInjectedRunner(): void
    {
        $commands = [];
        $manager = new DependencyManager(
            $this->composerJson,
            static function (array $command, string $cwd) use (&$commands): array {
                $commands[] = ['command' => $command, 'cwd' => $cwd];

                return [
                    'exit_code' => 0,
                    'stdout' => 'composer ok',
                    'stderr' => '',
                ];
            },
        );

        $requireResult = $manager->requirePackageNoUpdate('vendor/plugin', '*');
        $updateResult = $manager->updatePackage('vendor/plugin');

        self::assertTrue($requireResult['success']);
        self::assertTrue($updateResult['success']);
        self::assertCount(2, $commands);

        self::assertSame(['composer', 'require', '--no-update', 'vendor/plugin:*'], $commands[0]['command']);
        self::assertSame(['composer', 'update', '--no-scripts', 'vendor/plugin'], $commands[1]['command']);
        self::assertSame($this->projectDir, $commands[0]['cwd']);
    }

    public function testRealComposerExecutionCanBeSkippedWhenBinaryMissing(): void
    {
        if (!$this->isComposerAvailable()) {
            self::markTestSkipped('Requires Composer binary in test runtime environment');
        }

        $manager = new DependencyManager($this->composerJson);
        $result = $manager->requirePackageNoUpdate('psr/log', '^3.0');

        self::assertIsArray($result);
        self::assertArrayHasKey('success', $result);
    }

    private function isComposerAvailable(): bool
    {
        $output = [];
        $exitCode = 0;
        exec('composer --version 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }
}
