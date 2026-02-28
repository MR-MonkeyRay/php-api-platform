<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin;

use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Database\Migration\MigrationRunner;
use App\Core\Plugin\Installer\InstallationValidator;
use App\Core\Plugin\PluginManager;
use App\Core\Policy\PolicyProvider;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class InstallationValidationTest extends TestCase
{
    private string $workspace;
    private string $pluginsDir;
    private string $policyDir;
    private PDO $pdo;
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->workspace = sys_get_temp_dir() . '/installation-validator-integration-' . $suffix;
        $this->pluginsDir = $this->workspace . '/plugins';
        $this->policyDir = $this->workspace . '/policy';

        self::assertTrue(mkdir($this->pluginsDir, 0755, true) || is_dir($this->pluginsDir));
        self::assertTrue(mkdir($this->policyDir, 0755, true) || is_dir($this->policyDir));

        $databasePath = $this->workspace . '/database.sqlite';

        $factory = new ConnectionFactory(new Config([
            'database' => [
                'type' => 'sqlite',
                'path' => $databasePath,
            ],
        ]));
        $this->pdo = $factory->create();

        $runner = new MigrationRunner($this->pdo, 'sqlite', dirname(__DIR__, 3) . '/migrations');
        $runner->run();

        $this->logger = new Logger('installation-validator-test');
        $this->logger->pushHandler(new TestHandler(Level::Debug));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function testInstalledPluginCanBeLoaded(): void
    {
        $pluginDir = $this->installPlugin('test-plugin');

        $validator = new InstallationValidator($pluginDir, $this->pdo, $this->policyDir);
        $result = $validator->validate();

        self::assertTrue($result->isValid(), (string) $result->getError());

        $manager = new PluginManager($this->logger);
        $plugins = $manager->loadPlugins($this->pluginsDir);

        $ids = array_map(static fn ($plugin): string => $plugin->getId(), $plugins);
        self::assertContains('test-plugin', $ids);
    }

    public function testInstalledPluginRoutesWork(): void
    {
        $pluginDir = $this->installPlugin('test-plugin-routes');

        $validator = new InstallationValidator($pluginDir, $this->pdo, $this->policyDir);
        self::assertTrue($validator->validate()->isValid());

        $manager = new PluginManager($this->logger);
        $manager->loadPlugins($this->pluginsDir);

        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $manager->registerRoutes($app);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/test-plugin-routes/hello');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"message":"hello"}', (string) $response->getBody());
    }

    public function testSnapshotContainsPluginApis(): void
    {
        $pluginDir = $this->installPlugin('test-plugin-snapshot');

        $validator = new InstallationValidator($pluginDir, $this->pdo, $this->policyDir);
        $result = $validator->refreshSnapshot();

        self::assertTrue($result->isValid(), (string) $result->getError());
        self::assertFileExists($this->policyDir . '/snapshot.json');

        $snapshot = json_decode(
            (string) file_get_contents($this->policyDir . '/snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('test-plugin-snapshot:hello:get', $snapshot);
        self::assertSame(1, $snapshot['test-plugin-snapshot:hello:get']['enabled']);
    }

    public function testInstallAsDisabled(): void
    {
        $pluginDir = $this->installPlugin('test-plugin-disabled');

        $validator = new InstallationValidator($pluginDir, $this->pdo, $this->policyDir);
        $result = $validator->refreshSnapshot(disabled: true);

        self::assertTrue($result->isValid(), (string) $result->getError());

        $provider = new PolicyProvider($this->policyDir, debounceMilliseconds: 0.0);
        $policy = $provider->getPolicy('test-plugin-disabled:hello:get');

        self::assertIsArray($policy);
        self::assertSame(0, (int) ($policy['enabled'] ?? 1));
    }

    private function installPlugin(string $pluginId): string
    {
        $pluginDir = $this->pluginsDir . '/' . $pluginId;
        self::assertTrue(mkdir($pluginDir, 0755, true) || is_dir($pluginDir));

        $className = str_replace('-', '', ucwords($pluginId, '-'));

        file_put_contents(
            $pluginDir . '/plugin.json',
            (string) json_encode([
                'id' => $pluginId,
                'name' => 'Plugin ' . $pluginId,
                'version' => '1.0.0',
                'mainClass' => 'IntegrationTestPlugins\\' . $className,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        file_put_contents(
            $pluginDir . '/bootstrap.php',
            sprintf(
                <<<'PHP'
                <?php
                namespace IntegrationTestPlugins;

                use App\Core\Plugin\ApiDefinition;
                use App\Core\Plugin\PluginInterface;
                use Slim\App;

                final class %s implements PluginInterface
                {
                    public function getId(): string { return '%s'; }
                    public function getName(): string { return 'Plugin %s'; }
                    public function getVersion(): string { return '1.0.0'; }

                    public function routes(App $app): void
                    {
                        $app->get('/api/%s/hello', static function ($request, $response) {
                            $response->getBody()->write('{"message":"hello"}');

                            return $response->withHeader('Content-Type', 'application/json');
                        });
                    }

                    public function apis(): array
                    {
                        return [new ApiDefinition('%s:hello:get', 'public', ['read'])];
                    }
                }
                PHP,
                $className,
                $pluginId,
                $pluginId,
                $pluginId,
                $pluginId,
            )
        );

        return $pluginDir;
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
