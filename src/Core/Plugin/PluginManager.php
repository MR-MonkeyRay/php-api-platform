<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\App;
use Throwable;

final class PluginManager
{
    /**
     * @var array<string, PluginInterface>
     */
    private array $plugins = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<PluginInterface>
     */
    public function loadPlugins(string $directory): array
    {
        $pluginDirectories = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];

        foreach ($pluginDirectories as $pluginDirectory) {
            $metadataFile = $pluginDirectory . DIRECTORY_SEPARATOR . 'plugin.json';

            try {
                $metadata = PluginMetadata::fromFile($metadataFile);
                $plugin = $this->instantiatePlugin($pluginDirectory, $metadata);
                $this->plugins[$metadata->id] = $plugin;
            } catch (Throwable $exception) {
                $this->logger->warning('Plugin load skipped due to invalid plugin.', [
                    'directory' => $pluginDirectory,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        ksort($this->plugins);

        return array_values($this->plugins);
    }

    public function registerRoutes(App $app): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->routes($app);
        }
    }

    /**
     * @return list<ApiDefinition>
     */
    public function collectApis(): array
    {
        $definitions = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->apis() as $definition) {
                if ($definition instanceof ApiDefinition) {
                    $definitions[] = $definition;
                }
            }
        }

        return $definitions;
    }

    public function count(): int
    {
        return count($this->plugins);
    }

    private function instantiatePlugin(string $pluginDirectory, PluginMetadata $metadata): PluginInterface
    {
        $bootstrap = $pluginDirectory . DIRECTORY_SEPARATOR . 'bootstrap.php';
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }

        if (!class_exists($metadata->mainClass)) {
            throw new RuntimeException(
                sprintf('Plugin mainClass "%s" not found for plugin "%s".', $metadata->mainClass, $metadata->id)
            );
        }

        $plugin = new ($metadata->mainClass)();

        if (!$plugin instanceof PluginInterface) {
            throw new RuntimeException(
                sprintf('Plugin class "%s" must implement PluginInterface.', $metadata->mainClass)
            );
        }

        return $plugin;
    }
}
