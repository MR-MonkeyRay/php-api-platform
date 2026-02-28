<?php

declare(strict_types=1);

namespace App\Core\Plugin\Installer;

use App\Core\Plugin\ApiDefinition;
use App\Core\Plugin\InvalidPluginException;
use App\Core\Plugin\PluginInterface;
use App\Core\Plugin\PluginManager;
use App\Core\Plugin\PluginMetadata;
use App\Core\Policy\SnapshotBuilder;
use App\Core\Repository\ApiPolicyRepository;
use PDO;
use Psr\Log\NullLogger;
use Slim\Factory\AppFactory;
use Throwable;

final class InstallationValidator
{
    private readonly string $pluginDirectory;
    private readonly string $pluginsDirectory;
    private readonly string $policyDirectory;

    public function __construct(
        string $pluginDirectory,
        private readonly ?PDO $pdo = null,
        string $policyDirectory = 'var/policy',
    ) {
        $this->pluginDirectory = $this->resolvePath($pluginDirectory);
        $this->pluginsDirectory = dirname($this->pluginDirectory);
        $this->policyDirectory = $this->resolvePath($policyDirectory);
    }

    public function validatePluginJson(): InstallationValidationResult
    {
        try {
            $this->loadMetadata();
        } catch (Throwable $exception) {
            return InstallationValidationResult::invalid($exception->getMessage());
        }

        return InstallationValidationResult::valid();
    }

    public function validateMainClass(): InstallationValidationResult
    {
        try {
            $metadata = $this->loadMetadata();
            $this->includeBootstrapIfExists();

            if (!class_exists($metadata->mainClass)) {
                return InstallationValidationResult::invalid(
                    sprintf('Plugin main class "%s" not found.', $metadata->mainClass)
                );
            }

            if (!is_subclass_of($metadata->mainClass, PluginInterface::class)) {
                return InstallationValidationResult::invalid(
                    sprintf('Plugin main class "%s" must implement PluginInterface.', $metadata->mainClass)
                );
            }

            $plugin = new ($metadata->mainClass)();
            if (!$plugin instanceof PluginInterface) {
                return InstallationValidationResult::invalid('Plugin main class instance is invalid.');
            }

            if ($plugin->getId() !== $metadata->id) {
                return InstallationValidationResult::invalid(
                    sprintf('Plugin id mismatch. metadata=%s runtime=%s', $metadata->id, $plugin->getId())
                );
            }
        } catch (Throwable $exception) {
            return InstallationValidationResult::invalid($exception->getMessage());
        }

        return InstallationValidationResult::valid();
    }

    public function validatePluginLoadable(): InstallationValidationResult
    {
        try {
            $metadata = $this->loadMetadata();
            $plugin = $this->loadPluginById($metadata->id);

            if ($plugin === null) {
                return InstallationValidationResult::invalid(
                    sprintf('Plugin "%s" could not be loaded from %s.', $metadata->id, $this->pluginsDirectory)
                );
            }
        } catch (Throwable $exception) {
            return InstallationValidationResult::invalid($exception->getMessage());
        }

        return InstallationValidationResult::valid();
    }

    public function validateRoutesRegistrable(): InstallationValidationResult
    {
        $loadResult = $this->validatePluginLoadable();
        if (!$loadResult->isValid()) {
            return $loadResult;
        }

        try {
            $manager = $this->createPluginManager();
            $manager->loadPlugins($this->pluginsDirectory);

            $app = AppFactory::create();
            $manager->registerRoutes($app);
        } catch (Throwable $exception) {
            return InstallationValidationResult::invalid(
                sprintf('Plugin routes are not registrable: %s', $exception->getMessage())
            );
        }

        return InstallationValidationResult::valid();
    }

    public function validateApiMetadata(): InstallationValidationResult
    {
        try {
            $metadata = $this->loadMetadata();
            $plugin = $this->loadPluginById($metadata->id);

            if ($plugin === null) {
                return InstallationValidationResult::invalid(
                    sprintf('Plugin "%s" could not be loaded for API metadata validation.', $metadata->id)
                );
            }

            foreach ($plugin->apis() as $apiDefinition) {
                if (!$apiDefinition instanceof ApiDefinition) {
                    return InstallationValidationResult::invalid(
                        sprintf('Plugin "%s" returned invalid API metadata entry.', $metadata->id)
                    );
                }
            }
        } catch (Throwable $exception) {
            return InstallationValidationResult::invalid($exception->getMessage());
        }

        return InstallationValidationResult::valid();
    }

    public function validate(): InstallationValidationResult
    {
        $steps = [
            $this->validatePluginJson(),
            $this->validateMainClass(),
            $this->validatePluginLoadable(),
            $this->validateRoutesRegistrable(),
            $this->validateApiMetadata(),
        ];

        foreach ($steps as $result) {
            if (!$result->isValid()) {
                return $result;
            }
        }

        return InstallationValidationResult::valid();
    }

    public function refreshSnapshot(bool $disabled = false): InstallationValidationResult
    {
        $validation = $this->validate();
        if (!$validation->isValid()) {
            return $validation;
        }

        if (!$this->pdo instanceof PDO) {
            return InstallationValidationResult::invalid('PDO is required to refresh plugin snapshot.');
        }

        try {
            $metadata = $this->loadMetadata();
            $manager = $this->createPluginManager();
            $plugins = $manager->loadPlugins($this->pluginsDirectory);

            $targetPlugin = $this->findPluginById($plugins, $metadata->id);
            if ($targetPlugin === null) {
                return InstallationValidationResult::invalid(
                    sprintf('Plugin "%s" not found while refreshing snapshot.', $metadata->id)
                );
            }

            $apiDefinitions = $this->extractApiDefinitions($targetPlugin);
            $this->applyEnabledState($metadata->id, $apiDefinitions, !$disabled);

            $snapshotBuilder = new SnapshotBuilder($this->pdo, $this->policyDirectory);
            $snapshotBuilder->build($manager->collectApis());
        } catch (Throwable $exception) {
            return InstallationValidationResult::invalid(
                sprintf('Failed to refresh plugin snapshot: %s', $exception->getMessage())
            );
        }

        return InstallationValidationResult::valid();
    }

    public function enable(): InstallationValidationResult
    {
        return $this->refreshSnapshot(false);
    }

    public function disable(): InstallationValidationResult
    {
        return $this->refreshSnapshot(true);
    }

    private function includeBootstrapIfExists(): void
    {
        $bootstrapFile = $this->pluginDirectory . DIRECTORY_SEPARATOR . 'bootstrap.php';
        if (!is_file($bootstrapFile)) {
            return;
        }

        require_once $bootstrapFile;
    }

    private function loadMetadata(): PluginMetadata
    {
        return PluginMetadata::fromFile($this->pluginDirectory . DIRECTORY_SEPARATOR . 'plugin.json');
    }

    private function createPluginManager(): PluginManager
    {
        return new PluginManager(new NullLogger());
    }

    private function loadPluginById(string $pluginId): ?PluginInterface
    {
        $manager = $this->createPluginManager();
        $plugins = $manager->loadPlugins($this->pluginsDirectory);

        return $this->findPluginById($plugins, $pluginId);
    }

    /**
     * @param list<PluginInterface> $plugins
     */
    private function findPluginById(array $plugins, string $pluginId): ?PluginInterface
    {
        foreach ($plugins as $plugin) {
            if ($plugin->getId() === $pluginId) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * @return list<ApiDefinition>
     */
    private function extractApiDefinitions(PluginInterface $plugin): array
    {
        $apiDefinitions = [];

        foreach ($plugin->apis() as $apiDefinition) {
            if (!$apiDefinition instanceof ApiDefinition) {
                throw new InvalidPluginException(
                    sprintf('Plugin "%s" returned invalid API metadata entry.', $plugin->getId())
                );
            }

            $apiDefinitions[] = $apiDefinition;
        }

        return $apiDefinitions;
    }

    /**
     * @param list<ApiDefinition> $apiDefinitions
     */
    private function applyEnabledState(string $pluginId, array $apiDefinitions, bool $enabled): void
    {
        if ($this->pdo === null) {
            throw new InvalidPluginException('PDO is required for enable/disable state update.');
        }

        $repository = new ApiPolicyRepository($this->pdo);

        foreach ($apiDefinitions as $apiDefinition) {
            $repository->upsert([
                'api_id' => $apiDefinition->apiId,
                'plugin_id' => $pluginId,
                'enabled' => $enabled ? 1 : 0,
                'visibility' => $apiDefinition->visibilityDefault,
                'required_scopes' => $apiDefinition->requiredScopesDefault,
                'constraints' => new \stdClass(),
            ]);
        }
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidPluginException('Path cannot be empty.');
        }

        if ($this->isAbsolutePath($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}

final readonly class InstallationValidationResult
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private bool $valid,
        private ?string $error = null,
        private array $context = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function valid(array $context = []): self
    {
        return new self(
            valid: true,
            error: null,
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function invalid(string $error, array $context = []): self
    {
        return new self(
            valid: false,
            error: $error,
            context: $context,
        );
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
