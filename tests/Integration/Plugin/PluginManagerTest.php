<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin;

use App\Core\Plugin\PluginManager;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class PluginManagerTest extends TestCase
{
    private string $pluginsDir;
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginsDir = sys_get_temp_dir() . '/php-api-platform-plugins-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->pluginsDir, 0755, true) || is_dir($this->pluginsDir));

        $this->logger = new Logger('plugin-test');
        $this->logger->pushHandler(new TestHandler(Level::Debug));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->deleteDirectory($this->pluginsDir);
    }

    public function testLoadPlugins(): void
    {
        $this->createPlugin('valid-plugin', [
            'id' => 'valid-plugin',
            'name' => 'Valid Plugin',
            'version' => '1.0.0',
            'mainClass' => 'ValidPlugin',
        ],
            <<<'PHP'
            <?php
            use App\Core\Plugin\ApiDefinition;
            use App\Core\Plugin\PluginInterface;
            use Slim\App;

            final class ValidPlugin implements PluginInterface
            {
                public function getId(): string { return 'valid-plugin'; }
                public function getName(): string { return 'Valid Plugin'; }
                public function getVersion(): string { return '1.0.0'; }
                public function routes(App $app): void
                {
                    $app->get('/plugin/valid', static function ($request, $response) {
                        $response->getBody()->write('{"status":"ok"}');
                        return $response->withHeader('Content-Type', 'application/json');
                    });
                }
                public function apis(): array
                {
                    return [new ApiDefinition('valid:api:get', 'public', ['read'])];
                }
            }
            PHP
        );

        $manager = new PluginManager($this->logger);
        $plugins = $manager->loadPlugins($this->pluginsDir);

        self::assertCount(1, $plugins);
        self::assertSame('valid-plugin', $plugins[0]->getId());
    }

    public function testSkipInvalidPlugin(): void
    {
        $this->createPlugin('valid-plugin', [
            'id' => 'valid-plugin',
            'name' => 'Valid Plugin',
            'version' => '1.0.0',
            'mainClass' => 'ValidPluginForSkip',
        ],
            <<<'PHP'
            <?php
            use App\Core\Plugin\PluginInterface;
            use Slim\App;

            final class ValidPluginForSkip implements PluginInterface
            {
                public function getId(): string { return 'valid-plugin'; }
                public function getName(): string { return 'Valid Plugin'; }
                public function getVersion(): string { return '1.0.0'; }
                public function routes(App $app): void {}
                public function apis(): array { return []; }
            }
            PHP
        );

        $invalidDir = $this->pluginsDir . '/invalid-plugin';
        self::assertTrue(mkdir($invalidDir, 0755, true) || is_dir($invalidDir));

        $manager = new PluginManager($this->logger);
        $plugins = $manager->loadPlugins($this->pluginsDir);

        self::assertCount(1, $plugins);
    }

    public function testRegisterRoutes(): void
    {
        $this->createPlugin('route-plugin', [
            'id' => 'route-plugin',
            'name' => 'Route Plugin',
            'version' => '1.0.0',
            'mainClass' => 'RoutePlugin',
        ],
            <<<'PHP'
            <?php
            use App\Core\Plugin\PluginInterface;
            use Slim\App;

            final class RoutePlugin implements PluginInterface
            {
                public function getId(): string { return 'route-plugin'; }
                public function getName(): string { return 'Route Plugin'; }
                public function getVersion(): string { return '1.0.0'; }
                public function routes(App $app): void
                {
                    $app->get('/plugin/route', static function ($request, $response) {
                        return $response;
                    });
                }
                public function apis(): array { return []; }
            }
            PHP
        );

        $manager = new PluginManager($this->logger);
        $manager->loadPlugins($this->pluginsDir);

        $app = AppFactory::create();
        $manager->registerRoutes($app);

        $routes = $app->getRouteCollector()->getRoutes();

        self::assertNotEmpty($routes);
    }

    public function testCollectApis(): void
    {
        $this->createPlugin('api-plugin', [
            'id' => 'api-plugin',
            'name' => 'Api Plugin',
            'version' => '1.0.0',
            'mainClass' => 'ApiPlugin',
        ],
            <<<'PHP'
            <?php
            use App\Core\Plugin\ApiDefinition;
            use App\Core\Plugin\PluginInterface;
            use Slim\App;

            final class ApiPlugin implements PluginInterface
            {
                public function getId(): string { return 'api-plugin'; }
                public function getName(): string { return 'Api Plugin'; }
                public function getVersion(): string { return '1.0.0'; }
                public function routes(App $app): void {}
                public function apis(): array
                {
                    return [new ApiDefinition('api-plugin:test:get', 'public', ['read'])];
                }
            }
            PHP
        );

        $manager = new PluginManager($this->logger);
        $manager->loadPlugins($this->pluginsDir);
        $apis = $manager->collectApis();

        self::assertCount(1, $apis);
        self::assertSame('api-plugin:test:get', $apis[0]->apiId);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function createPlugin(string $name, array $metadata, string $bootstrapPhp): void
    {
        $dir = $this->pluginsDir . '/' . $name;
        self::assertTrue(mkdir($dir, 0755, true) || is_dir($dir));

        file_put_contents(
            $dir . '/plugin.json',
            (string) json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        file_put_contents($dir . '/bootstrap.php', $bootstrapPhp);
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

            unlink($path);
        }

        rmdir($directory);
    }
}
