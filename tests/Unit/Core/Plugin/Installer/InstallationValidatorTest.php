<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plugin\Installer;

use App\Core\Plugin\Installer\InstallationValidator;
use PHPUnit\Framework\TestCase;

final class InstallationValidatorTest extends TestCase
{
    private string $workspace;
    private string $pluginsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->workspace = sys_get_temp_dir() . '/installation-validator-unit-' . $suffix;
        $this->pluginsDir = $this->workspace . '/plugins';

        self::assertTrue(mkdir($this->pluginsDir, 0755, true) || is_dir($this->pluginsDir));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function testValidatePluginJsonPassesForValidPlugin(): void
    {
        $pluginDir = $this->createPlugin('valid-plugin');

        $validator = new InstallationValidator($pluginDir);
        $result = $validator->validatePluginJson();

        self::assertTrue($result->isValid());
        self::assertNull($result->getError());
    }

    public function testValidatePluginJsonFailsWhenFileMissing(): void
    {
        $pluginDir = $this->pluginsDir . '/missing-plugin-json';
        self::assertTrue(mkdir($pluginDir, 0755, true) || is_dir($pluginDir));

        $validator = new InstallationValidator($pluginDir);
        $result = $validator->validatePluginJson();

        self::assertFalse($result->isValid());
        self::assertNotNull($result->getError());
        self::assertStringContainsString('plugin.json', strtolower((string) $result->getError()));
    }

    public function testValidateMainClassPassesWhenPluginImplementsContract(): void
    {
        $pluginDir = $this->createPlugin('main-class-ok');

        $validator = new InstallationValidator($pluginDir);
        $result = $validator->validateMainClass();

        self::assertTrue($result->isValid());
        self::assertNull($result->getError());
    }

    public function testValidateMainClassFailsWhenClassMissing(): void
    {
        $pluginDir = $this->createPlugin('main-class-missing', [
            'mainClass' => 'UnitTestPlugins\\MissingMainClass',
        ], bootstrapPhp: "<?php\n");

        $validator = new InstallationValidator($pluginDir);
        $result = $validator->validateMainClass();

        self::assertFalse($result->isValid());
        self::assertNotNull($result->getError());
        self::assertStringContainsString('not found', strtolower((string) $result->getError()));
    }

    public function testValidateMainClassFailsWhenClassDoesNotImplementPluginInterface(): void
    {
        $pluginDir = $this->createPlugin(
            'main-class-invalid-contract',
            [
                'mainClass' => 'UnitTestPlugins\\InvalidContractPlugin',
            ],
            <<<'PHP'
            <?php
            namespace UnitTestPlugins;

            final class InvalidContractPlugin
            {
            }
            PHP
        );

        $validator = new InstallationValidator($pluginDir);
        $result = $validator->validateMainClass();

        self::assertFalse($result->isValid());
        self::assertNotNull($result->getError());
        self::assertStringContainsString('implement plugininterface', strtolower((string) $result->getError()));
    }

    public function testValidateApiMetadataFailsWhenPluginReturnsInvalidEntry(): void
    {
        $pluginDir = $this->createPlugin(
            'invalid-api-metadata',
            [
                'mainClass' => 'UnitTestPlugins\\InvalidApiMetadataPlugin',
            ],
            <<<'PHP'
            <?php
            namespace UnitTestPlugins;

            use App\Core\Plugin\PluginInterface;
            use Slim\App;

            final class InvalidApiMetadataPlugin implements PluginInterface
            {
                public function getId(): string { return 'invalid-api-metadata'; }
                public function getName(): string { return 'Invalid API metadata'; }
                public function getVersion(): string { return '1.0.0'; }
                public function routes(App $app): void {}
                public function apis(): array { return ['invalid']; }
            }
            PHP
        );

        $validator = new InstallationValidator($pluginDir);
        $result = $validator->validateApiMetadata();

        self::assertFalse($result->isValid());
        self::assertNotNull($result->getError());
        self::assertStringContainsString('invalid api metadata', strtolower((string) $result->getError()));
    }

    /**
     * @param array<string, mixed> $metadataOverrides
     */
    private function createPlugin(string $pluginId, array $metadataOverrides = [], ?string $bootstrapPhp = null): string
    {
        $pluginDir = $this->pluginsDir . '/' . $pluginId;
        self::assertTrue(mkdir($pluginDir, 0755, true) || is_dir($pluginDir));

        $className = 'UnitTestPlugins\\' . str_replace('-', '', ucwords($pluginId, '-')) . bin2hex(random_bytes(4));

        $metadata = array_merge([
            'id' => $pluginId,
            'name' => 'Plugin ' . $pluginId,
            'version' => '1.0.0',
            'mainClass' => $className,
        ], $metadataOverrides);

        file_put_contents(
            $pluginDir . '/plugin.json',
            (string) json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        if ($bootstrapPhp === null) {
            $bootstrapPhp = sprintf(
                <<<'PHP'
                <?php
                namespace UnitTestPlugins;

                use App\Core\Plugin\ApiDefinition;
                use App\Core\Plugin\PluginInterface;
                use Slim\App;

                final class %s implements PluginInterface
                {
                    public function getId(): string { return '%s'; }
                    public function getName(): string { return 'Plugin %s'; }
                    public function getVersion(): string { return '1.0.0'; }
                    public function routes(App $app): void {}
                    public function apis(): array { return [new ApiDefinition('%s:hello:get', 'public', [])]; }
                }
                PHP,
                $this->shortClassName((string) $metadata['mainClass']),
                $pluginId,
                $pluginId,
                $pluginId,
            );
        }

        file_put_contents($pluginDir . '/bootstrap.php', $bootstrapPhp);

        return $pluginDir;
    }

    private function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return (string) end($parts);
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
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}
