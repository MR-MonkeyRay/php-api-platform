<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin\Installer;

use App\Core\Plugin\Installer\DependencyManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DependencyManagerTest extends TestCase
{
    private string $projectDir;
    private string $composerJson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir() . '/dependency-manager-unit-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->projectDir, 0755, true) || is_dir($this->projectDir));

        $this->composerJson = $this->projectDir . '/composer.json';
        file_put_contents(
            $this->composerJson,
            (string) json_encode([
                'name' => 'tests/dependency-manager',
                'require' => new \stdClass(),
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

    public function testAnalyzeDependenciesSeparatesPlatformAndPackages(): void
    {
        $manager = new DependencyManager($this->composerJson);

        $result = $manager->analyzeDependencies([
            'platform/core' => '^1.0',
            'ext-json' => '*',
            'php' => '^8.4',
            'acme/plugin' => '^2.1',
        ]);

        self::assertCount(4, $result['dependencies']);
        self::assertCount(2, $result['package_dependencies']);
        self::assertCount(2, $result['platform_dependencies']);
        self::assertTrue($result['requires_confirmation']);

        self::assertSame('acme/plugin', $result['dependencies'][0]['package']);
        self::assertFalse($result['dependencies'][0]['is_platform']);
        self::assertSame('ext-json', $result['dependencies'][1]['package']);
        self::assertTrue($result['dependencies'][1]['is_platform']);
    }

    public function testAnalyzeDependenciesSupportsColonNotationEntries(): void
    {
        $manager = new DependencyManager($this->composerJson);

        $result = $manager->analyzeDependencies([
            'vendor/foo:^1.0',
            'ext-mbstring:*',
            'invalid-entry',
        ]);

        self::assertCount(2, $result['dependencies']);
        self::assertSame('vendor/foo', $result['package_dependencies'][0]['package']);
        self::assertSame('ext-mbstring', $result['platform_dependencies'][0]['package']);
    }

    public function testAddPathRepositoryWritesComposerJsonWithoutDuplicate(): void
    {
        $manager = new DependencyManager($this->composerJson);

        $first = $manager->addPathRepository('plugins/example-plugin');
        self::assertTrue($first['changed']);
        self::assertCount(1, $first['repositories']);

        $second = $manager->addPathRepository('plugins/example-plugin');
        self::assertFalse($second['changed']);
        self::assertCount(1, $second['repositories']);

        $composer = json_decode((string) file_get_contents($this->composerJson), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('path', $composer['repositories'][0]['type']);
        self::assertSame('plugins/example-plugin', $composer['repositories'][0]['url']);
    }

    public function testBuildCommandsWithExpectedFlags(): void
    {
        $manager = new DependencyManager($this->composerJson);

        self::assertSame(
            ['composer', 'require', '--no-update', 'vendor/plugin:^1.2'],
            $manager->buildRequireCommand('vendor/plugin', '^1.2'),
        );
        self::assertSame(
            ['composer', 'update', '--no-scripts', 'vendor/plugin'],
            $manager->buildUpdateCommand('vendor/plugin'),
        );
    }

    public function testRequireAndUpdateUseInjectedRunner(): void
    {
        $executed = [];
        $runner = static function (array $command, string $cwd) use (&$executed): array {
            $executed[] = ['command' => $command, 'cwd' => $cwd];

            return [
                'exit_code' => 0,
                'stdout' => 'ok',
                'stderr' => '',
            ];
        };

        $manager = new DependencyManager($this->composerJson, $runner);

        $require = $manager->requirePackageNoUpdate('vendor/plugin', '*');
        $update = $manager->updatePackage('vendor/plugin');

        self::assertTrue($require['success']);
        self::assertTrue($update['success']);
        self::assertSame(['composer', 'require', '--no-update', 'vendor/plugin:*'], $executed[0]['command']);
        self::assertSame(['composer', 'update', '--no-scripts', 'vendor/plugin'], $executed[1]['command']);
        self::assertSame($this->projectDir, $executed[0]['cwd']);
    }

    public function testInvalidPackageNameThrowsException(): void
    {
        $manager = new DependencyManager($this->composerJson);

        $this->expectException(InvalidArgumentException::class);
        $manager->buildRequireCommand('invalid-package-name', '*');
    }
}
