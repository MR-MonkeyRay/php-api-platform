<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin\Installer;

use App\Core\Plugin\Installer\PluginDownloader;
use App\Core\Plugin\Installer\PluginInstaller;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class PluginInstallerTest extends TestCase
{
    private string $projectRoot;
    private string $pluginsDirectory;
    private PluginDownloader $downloader;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->projectRoot = sys_get_temp_dir() . '/plugin-installer-project-' . $suffix;
        $this->pluginsDirectory = $this->projectRoot . '/plugins';

        self::assertTrue(mkdir($this->projectRoot, 0755, true) || is_dir($this->projectRoot));
        self::assertTrue(mkdir($this->pluginsDirectory, 0755, true) || is_dir($this->pluginsDirectory));

        $this->writeProjectComposerFiles();

        $this->downloader = new PluginDownloader($this->projectRoot, new Logger('plugin-installer-test'), 30);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->projectRoot);

        parent::tearDown();
    }

    public function testInstallPluginSuccess(): void
    {
        $repository = $this->createPluginRepository('sample-plugin');

        $validator = new FakeValidator();
        $dependencyManager = new FakeDependencyManager($this->projectRoot);
        $pluginManager = new FakePluginManager();
        $snapshotBuilder = new FakeSnapshotBuilder();

        $rollbackCommands = [];
        $installer = new PluginInstaller(
            $validator,
            $this->downloader,
            $dependencyManager,
            $pluginManager,
            $snapshotBuilder,
            $this->pluginsDirectory,
            $this->projectRoot,
            static function (string $command) use (&$rollbackCommands): array {
                $rollbackCommands[] = $command;

                return [
                    'success' => true,
                    'output' => 'ok',
                    'exit_code' => 0,
                ];
            },
        );

        $result = $installer->install($repository, 'v1.0.0', ['accept_deps' => true]);

        self::assertTrue($result['success']);
        self::assertSame('sample-plugin', $result['plugin_id']);
        self::assertDirectoryExists($this->pluginsDirectory . '/sample-plugin');
        self::assertFalse($result['rollback_performed']);

        self::assertContains('addPathRepository', $dependencyManager->operationNames());
        self::assertContains('requirePackage', $dependencyManager->operationNames());
        self::assertContains('updatePackage', $dependencyManager->operationNames());

        self::assertCount(1, $pluginManager->loadCalls);
        self::assertCount(1, $snapshotBuilder->buildCalls);

        self::assertSame([], $rollbackCommands);

        $this->deleteDirectory($repository);
    }

    public function testInstallReturnsDependencyConfirmationWhenNotAccepted(): void
    {
        $repository = $this->createPluginRepository('plugin-with-deps', [
            'vendor/dependency' => '^2.0',
        ]);

        $installer = new PluginInstaller(
            new FakeValidator(),
            $this->downloader,
            new FakeDependencyManager($this->projectRoot),
            new FakePluginManager(),
            new FakeSnapshotBuilder(),
            $this->pluginsDirectory,
            $this->projectRoot,
        );

        $result = $installer->install($repository, 'v1.0.0');

        self::assertFalse($result['success']);
        self::assertTrue($result['requires_confirmation']);
        self::assertContains('vendor/dependency:^2.0', $result['dependencies']);
        self::assertDirectoryDoesNotExist($this->pluginsDirectory . '/plugin-with-deps');

        $this->deleteDirectory($repository);
    }

    public function testInstallRollbackRestoresComposerAndCleansPluginOnFailure(): void
    {
        $repository = $this->createPluginRepository('plugin-bad-deps', [
            'vendor/conflict' => '^1.0',
        ]);

        $originalComposerJson = (string) file_get_contents($this->projectRoot . '/composer.json');
        $originalComposerLock = (string) file_get_contents($this->projectRoot . '/composer.lock');

        $dependencyManager = new FakeDependencyManager($this->projectRoot, failOnUpdate: true);

        $rollbackCommands = [];
        $installer = new PluginInstaller(
            new FakeValidator(),
            $this->downloader,
            $dependencyManager,
            new FakePluginManager(),
            new FakeSnapshotBuilder(),
            $this->pluginsDirectory,
            $this->projectRoot,
            static function (string $command) use (&$rollbackCommands): array {
                $rollbackCommands[] = $command;

                return [
                    'success' => true,
                    'output' => 'composer install executed',
                    'exit_code' => 0,
                ];
            },
        );

        $result = $installer->install($repository, 'v1.0.0', ['accept_deps' => true]);

        self::assertFalse($result['success']);
        self::assertTrue($result['rollback_performed']);
        self::assertDirectoryDoesNotExist($this->pluginsDirectory . '/plugin-bad-deps');

        $currentComposerJson = (string) file_get_contents($this->projectRoot . '/composer.json');
        $currentComposerLock = (string) file_get_contents($this->projectRoot . '/composer.lock');

        self::assertSame($originalComposerJson, $currentComposerJson);
        self::assertSame($originalComposerLock, $currentComposerLock);
        self::assertContains('composer install --no-scripts', $rollbackCommands);

        $this->deleteDirectory($repository);
    }

    public function testUninstallPluginRemovesDirectoryAndRefreshesSnapshot(): void
    {
        $pluginId = 'sample-plugin';
        $pluginDirectory = $this->pluginsDirectory . '/' . $pluginId;

        self::assertTrue(mkdir($pluginDirectory, 0755, true) || is_dir($pluginDirectory));
        file_put_contents(
            $pluginDirectory . '/plugin.json',
            (string) json_encode([
                'id' => $pluginId,
                'name' => 'Sample Plugin',
                'version' => '1.0.0',
                'mainClass' => 'SamplePlugin',
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $pluginDirectory . '/composer.json',
            (string) json_encode([
                'name' => 'vendor/sample-plugin',
                'require' => [
                    'php' => '^8.4',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $dependencyManager = new FakeDependencyManager($this->projectRoot);
        $pluginManager = new FakePluginManager();
        $snapshotBuilder = new FakeSnapshotBuilder();

        $installer = new PluginInstaller(
            new FakeValidator(),
            $this->downloader,
            $dependencyManager,
            $pluginManager,
            $snapshotBuilder,
            $this->pluginsDirectory,
            $this->projectRoot,
        );

        $result = $installer->uninstall($pluginId);

        self::assertTrue($result['success']);
        self::assertDirectoryDoesNotExist($pluginDirectory);
        self::assertContains('removePackage', $dependencyManager->operationNames());
        self::assertCount(1, $pluginManager->loadCalls);
        self::assertCount(1, $snapshotBuilder->buildCalls);
    }

    /**
     * @param array<string, string> $pluginRequire
     */
    private function createPluginRepository(string $pluginId, array $pluginRequire = []): string
    {
        $repository = sys_get_temp_dir() . '/plugin-installer-repository-' . $pluginId . '-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($repository, 0755, true) || is_dir($repository));

        $this->runCommand(sprintf('git -C %s init --quiet', escapeshellarg($repository)));
        $this->runCommand(sprintf('git -C %s config user.email "tester@example.com"', escapeshellarg($repository)));
        $this->runCommand(sprintf('git -C %s config user.name "Test User"', escapeshellarg($repository)));

        file_put_contents(
            $repository . '/plugin.json',
            (string) json_encode([
                'id' => $pluginId,
                'name' => 'Plugin ' . $pluginId,
                'version' => '1.0.0',
                'mainClass' => 'SamplePlugin',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        file_put_contents(
            $repository . '/composer.json',
            (string) json_encode([
                'name' => 'vendor/' . $pluginId,
                'require' => array_merge(['php' => '^8.4'], $pluginRequire),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        file_put_contents($repository . '/README.md', "plugin\n");

        $this->runCommand(sprintf('git -C %s add .', escapeshellarg($repository)));
        $this->runCommand(sprintf('git -C %s commit -m "init" --quiet', escapeshellarg($repository)));
        $this->runCommand(sprintf('git -C %s tag v1.0.0', escapeshellarg($repository)));

        return $repository;
    }

    private function writeProjectComposerFiles(): void
    {
        file_put_contents(
            $this->projectRoot . '/composer.json',
            (string) json_encode([
                'name' => 'example/project',
                'require' => [
                    'php' => '^8.4',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        file_put_contents(
            $this->projectRoot . '/composer.lock',
            (string) json_encode([
                'packages' => [],
                'packages-dev' => [],
                'content-hash' => md5('initial'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function runCommand(string $command): void
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}

final class FakeValidator
{
    public function __construct(
        private readonly bool $valid = true,
        private readonly ?string $error = null,
    ) {
    }

    public function validate(string $repositoryUrl, string $ref): object
    {
        return (object) [
            'valid' => $this->valid,
            'error' => $this->error,
            'canonicalRepositoryUrl' => $repositoryUrl,
        ];
    }
}

final class FakeDependencyManager
{
    /**
     * @var list<array<string, string>>
     */
    private array $operations = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly bool $failOnUpdate = false,
        private readonly bool $failOnRemove = false,
    ) {
    }

    /**
     * @param array<string, string> $pluginDeps
     *
     * @return array{dependencies:list<string>}
     */
    public function analyzeDependencies(array $pluginDeps): array
    {
        $dependencies = [];
        foreach ($pluginDeps as $package => $constraint) {
            $dependencies[] = sprintf('%s:%s', $package, $constraint);
        }

        sort($dependencies);

        return ['dependencies' => $dependencies];
    }

    /**
     * @return array{success:bool,error:?string}
     */
    public function addPathRepository(string $path): array
    {
        $this->operations[] = ['name' => 'addPathRepository', 'arg' => $path];

        $composer = $this->readComposer();
        $repositories = is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [];
        $repositories[] = [
            'type' => 'path',
            'url' => $path,
        ];
        $composer['repositories'] = $repositories;
        $this->writeComposer($composer);

        return ['success' => true, 'error' => null];
    }

    /**
     * @return array{success:bool,error:?string}
     */
    public function requirePackage(string $package, string $constraint): array
    {
        $this->operations[] = ['name' => 'requirePackage', 'arg' => $package . ':' . $constraint];

        $composer = $this->readComposer();
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        $require[$package] = $constraint;
        ksort($require);
        $composer['require'] = $require;

        $this->writeComposer($composer);

        return ['success' => true, 'error' => null];
    }

    /**
     * @return array{success:bool,error:?string}
     */
    public function updatePackage(string $package): array
    {
        $this->operations[] = ['name' => 'updatePackage', 'arg' => $package];

        if ($this->failOnUpdate) {
            return ['success' => false, 'error' => 'dependency update failed'];
        }

        file_put_contents($this->projectRoot . '/composer.lock', "updated\n");

        return ['success' => true, 'error' => null];
    }

    /**
     * @return array{success:bool,error:?string}
     */
    public function removePackage(string $package): array
    {
        $this->operations[] = ['name' => 'removePackage', 'arg' => $package];

        if ($this->failOnRemove) {
            return ['success' => false, 'error' => 'dependency remove failed'];
        }

        $composer = $this->readComposer();
        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
        unset($require[$package]);
        $composer['require'] = $require;
        $this->writeComposer($composer);

        return ['success' => true, 'error' => null];
    }

    /**
     * @return list<string>
     */
    public function operationNames(): array
    {
        return array_values(array_map(
            static fn (array $operation): string => (string) ($operation['name'] ?? ''),
            $this->operations,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposer(): array
    {
        $content = file_get_contents($this->projectRoot . '/composer.json');

        return json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function writeComposer(array $composer): void
    {
        file_put_contents(
            $this->projectRoot . '/composer.json',
            (string) json_encode(
                $composer,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );
    }
}

final class FakePluginManager
{
    /**
     * @var list<string>
     */
    public array $loadCalls = [];

    public function loadPlugins(string $directory): array
    {
        $this->loadCalls[] = $directory;

        return [];
    }

    public function collectApis(): array
    {
        return [];
    }
}

final class FakeSnapshotBuilder
{
    /**
     * @var list<array<int, mixed>>
     */
    public array $buildCalls = [];

    public function build(array $pluginApis): void
    {
        $this->buildCalls[] = $pluginApis;
    }
}
