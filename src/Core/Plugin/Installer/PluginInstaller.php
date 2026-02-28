<?php

declare(strict_types=1);

namespace App\Core\Plugin\Installer;

use App\Core\Plugin\PluginMetadata;
use RuntimeException;
use Throwable;

final class PluginInstaller
{
    private readonly string $projectRoot;
    private readonly string $pluginsDirectory;
    private readonly string $workspaceDirectory;

    /**
     * @var callable(string): array{success:bool,output:string,exit_code:int}
     */
    private $commandRunner;

    public function __construct(
        private readonly object $validator,
        private readonly object $downloader,
        private readonly object $dependencyManager,
        private readonly object $pluginManager,
        private readonly object $snapshotBuilder,
        string $pluginsDirectory = 'plugins',
        ?string $projectRoot = null,
        ?callable $commandRunner = null,
    ) {
        $resolvedRoot = $projectRoot !== null && trim($projectRoot) !== ''
            ? $projectRoot
            : dirname(__DIR__, 4);

        $this->projectRoot = $this->resolvePath($resolvedRoot);
        $this->pluginsDirectory = $this->resolvePath($pluginsDirectory);
        $this->workspaceDirectory = $this->resolvePath('var/plugin-installer');

        $this->commandRunner = $commandRunner ?? fn (string $command): array => $this->runCommand($command);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function install(string $repositoryUrl, string $ref, array $options = []): array
    {
        $transaction = $this->newTransactionContext();

        try {
            $this->ensureDirectoryExists($this->workspaceDirectory);
            $this->backupComposerFiles($transaction);

            $validation = $this->validateRepository($repositoryUrl, $ref);
            if (!$validation['valid']) {
                $this->finalizeBackups($transaction);

                return $this->failureResult(
                    error: (string) ($validation['error'] ?? 'Repository validation failed.'),
                    rollbackPerformed: false,
                );
            }

            $downloadDirectory = $this->workspaceDirectory . DIRECTORY_SEPARATOR . 'download-' . $transaction['id'];
            $transaction['download_directory'] = $downloadDirectory;

            $downloadResult = $this->downloadPlugin(
                (string) $validation['canonical_url'],
                $ref,
                $downloadDirectory,
            );
            if (!$downloadResult['success']) {
                return $this->rollbackFailure(
                    transaction: $transaction,
                    message: (string) ($downloadResult['error'] ?? 'Plugin download failed.'),
                );
            }

            $pluginDirectory = (string) ($downloadResult['destination'] ?? $downloadDirectory);
            $metadata = PluginMetadata::fromFile($pluginDirectory . DIRECTORY_SEPARATOR . 'plugin.json');
            $pluginId = $metadata->id;

            if (!$this->isSafePluginId($pluginId)) {
                return $this->rollbackFailure($transaction, 'Plugin ID is not safe for filesystem operations.');
            }

            $targetDirectory = $this->pluginsDirectory . DIRECTORY_SEPARATOR . $pluginId;
            if (is_dir($targetDirectory)) {
                return $this->rollbackFailure($transaction, sprintf('Plugin "%s" is already installed.', $pluginId));
            }

            $transaction['target_directory'] = $targetDirectory;

            $pluginDependencies = $this->extractPluginDependencies($pluginDirectory);
            $analysis = $this->analyzeDependencies($pluginDependencies);
            $dependencies = $analysis['dependencies'];

            $acceptDependencies = (bool) ($options['accept_deps'] ?? false);
            if ($dependencies !== [] && !$acceptDependencies) {
                $this->deleteDirectory($pluginDirectory);
                $this->finalizeBackups($transaction);

                return $this->failureResult(
                    error: 'Dependency confirmation required.',
                    requiresConfirmation: true,
                    dependencies: $dependencies,
                    rollbackPerformed: false,
                );
            }

            $packageName = $this->resolvePackageName($pluginDirectory, $pluginId);
            $dependencyInstall = $this->installDependencies($pluginDirectory, $packageName);
            if (!$dependencyInstall['success']) {
                return $this->rollbackFailure(
                    transaction: $transaction,
                    message: (string) ($dependencyInstall['error'] ?? 'Dependency installation failed.'),
                );
            }

            $this->ensureDirectoryExists(dirname($targetDirectory));
            if (!rename($pluginDirectory, $targetDirectory)) {
                return $this->rollbackFailure($transaction, sprintf('Failed to move plugin to %s.', $targetDirectory));
            }

            $transaction['plugin_moved'] = true;

            $this->refreshPluginSnapshot();
            $this->finalizeBackups($transaction);

            return [
                'success' => true,
                'error' => null,
                'requires_confirmation' => false,
                'dependencies' => [],
                'plugin_id' => $pluginId,
                'plugin_dir' => $targetDirectory,
                'rollback_performed' => false,
            ];
        } catch (Throwable $exception) {
            return $this->rollbackFailure($transaction, $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function uninstall(string $pluginId): array
    {
        $pluginId = trim($pluginId);
        if (!$this->isSafePluginId($pluginId)) {
            return $this->failureResult('Plugin ID is invalid.', rollbackPerformed: false);
        }

        $transaction = $this->newTransactionContext();

        try {
            $this->ensureDirectoryExists($this->workspaceDirectory);
            $this->backupComposerFiles($transaction);

            $targetDirectory = $this->pluginsDirectory . DIRECTORY_SEPARATOR . $pluginId;
            if (!is_dir($targetDirectory)) {
                $this->finalizeBackups($transaction);

                return $this->failureResult(
                    error: sprintf('Plugin "%s" is not installed.', $pluginId),
                    rollbackPerformed: false,
                );
            }

            $transaction['target_directory'] = $targetDirectory;

            $pluginBackupDirectory = $this->workspaceDirectory . DIRECTORY_SEPARATOR . 'plugin-backup-' . $transaction['id'];
            $transaction['plugin_backup_directory'] = $pluginBackupDirectory;

            $this->copyDirectory($targetDirectory, $pluginBackupDirectory);

            $packageName = $this->resolvePackageName($targetDirectory, $pluginId);
            $this->deleteDirectory($targetDirectory);

            $dependencyRemoval = $this->removeDependencies($packageName);
            if (!$dependencyRemoval['success']) {
                throw new RuntimeException((string) ($dependencyRemoval['error'] ?? 'Dependency removal failed.'));
            }

            $this->refreshPluginSnapshot();
            $this->finalizeBackups($transaction);

            return [
                'success' => true,
                'error' => null,
                'requires_confirmation' => false,
                'dependencies' => [],
                'plugin_id' => $pluginId,
                'plugin_dir' => null,
                'rollback_performed' => false,
            ];
        } catch (Throwable $exception) {
            return $this->rollbackFailure(
                transaction: $transaction,
                message: $exception->getMessage(),
                restorePluginFromBackup: true,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function newTransactionContext(): array
    {
        $transactionId = bin2hex(random_bytes(8));
        $backupDirectory = $this->workspaceDirectory . DIRECTORY_SEPARATOR . 'backups-' . $transactionId;

        return [
            'id' => $transactionId,
            'backup_directory' => $backupDirectory,
            'composer_json_backup' => $backupDirectory . DIRECTORY_SEPARATOR . 'composer.json.bak',
            'composer_lock_backup' => $backupDirectory . DIRECTORY_SEPARATOR . 'composer.lock.bak',
            'composer_json_initially_exists' => is_file($this->composerJsonFile()),
            'composer_lock_initially_exists' => is_file($this->composerLockFile()),
            'download_directory' => null,
            'target_directory' => null,
            'plugin_backup_directory' => null,
            'plugin_moved' => false,
        ];
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function backupComposerFiles(array &$transaction): void
    {
        $composerJson = $this->composerJsonFile();

        if (!is_file($composerJson)) {
            throw new RuntimeException(sprintf('composer.json not found at %s', $composerJson));
        }

        $this->ensureDirectoryExists((string) $transaction['backup_directory']);

        if (!copy($composerJson, (string) $transaction['composer_json_backup'])) {
            throw new RuntimeException('Failed to backup composer.json.');
        }

        $composerLock = $this->composerLockFile();
        if (is_file($composerLock) && !copy($composerLock, (string) $transaction['composer_lock_backup'])) {
            throw new RuntimeException('Failed to backup composer.lock.');
        }
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function restoreComposerFiles(array $transaction): string
    {
        $messages = [];

        $composerJsonBackup = (string) ($transaction['composer_json_backup'] ?? '');
        if ($composerJsonBackup !== '' && is_file($composerJsonBackup)) {
            if (!copy($composerJsonBackup, $this->composerJsonFile())) {
                $messages[] = 'Failed to restore composer.json from backup.';
            }
        }

        $composerLockBackup = (string) ($transaction['composer_lock_backup'] ?? '');
        $composerLockFile = $this->composerLockFile();

        if ($composerLockBackup !== '' && is_file($composerLockBackup)) {
            if (!copy($composerLockBackup, $composerLockFile)) {
                $messages[] = 'Failed to restore composer.lock from backup.';
            }
        } elseif (($transaction['composer_lock_initially_exists'] ?? false) === false && is_file($composerLockFile)) {
            @unlink($composerLockFile);
        }

        return implode(' ', $messages);
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function finalizeBackups(array $transaction): void
    {
        $this->deleteDirectory((string) ($transaction['download_directory'] ?? ''));
        $this->deleteDirectory((string) ($transaction['plugin_backup_directory'] ?? ''));
        $this->deleteDirectory((string) ($transaction['backup_directory'] ?? ''));
    }

    /**
     * @param array<string, mixed> $transaction
     *
     * @return array<string, mixed>
     */
    private function rollbackFailure(
        array $transaction,
        string $message,
        bool $restorePluginFromBackup = false,
    ): array {
        $targetDirectory = (string) ($transaction['target_directory'] ?? '');

        if (($transaction['plugin_moved'] ?? false) === true && $targetDirectory !== '' && is_dir($targetDirectory)) {
            $this->deleteDirectory($targetDirectory);
        }

        if ($restorePluginFromBackup) {
            $backupDirectory = (string) ($transaction['plugin_backup_directory'] ?? '');
            if ($backupDirectory !== '' && is_dir($backupDirectory) && $targetDirectory !== '' && !is_dir($targetDirectory)) {
                $this->copyDirectory($backupDirectory, $targetDirectory);
            }
        }

        $restoreError = $this->restoreComposerFiles($transaction);

        $composerInstall = $this->runComposerInstall();
        if (!$composerInstall['success']) {
            $message .= ' | rollback composer install failed: ' . trim((string) $composerInstall['output']);
        }

        if ($restoreError !== '') {
            $message .= ' | ' . $restoreError;
        }

        $this->finalizeBackups($transaction);

        return $this->failureResult($message, rollbackPerformed: true);
    }

    /**
     * @return array{valid:bool,error:?string,canonical_url:string}
     */
    private function validateRepository(string $repositoryUrl, string $ref): array
    {
        if (!method_exists($this->validator, 'validate')) {
            throw new RuntimeException('Validator must provide validate(string $repositoryUrl, string $ref).');
        }

        $result = $this->validator->validate($repositoryUrl, $ref);

        $valid = (bool) $this->extractValue($result, ['valid', 'isValid']);
        $error = $this->nullableString($this->extractValue($result, ['error', 'getError']));
        $canonical = $this->nullableString($this->extractValue($result, ['canonicalRepositoryUrl', 'canonical_url']));

        return [
            'valid' => $valid,
            'error' => $error,
            'canonical_url' => $canonical ?? trim($repositoryUrl),
        ];
    }

    /**
     * @return array{success:bool,error:?string,destination:string,output:string}
     */
    private function downloadPlugin(string $repositoryUrl, string $ref, string $destination): array
    {
        if (!method_exists($this->downloader, 'download')) {
            throw new RuntimeException('Downloader must provide download(string $repositoryUrl, string $ref, string $destination).');
        }

        $result = $this->downloader->download($repositoryUrl, $ref, $destination);

        return [
            'success' => (bool) $this->extractValue($result, ['success']),
            'error' => $this->nullableString($this->extractValue($result, ['error'])),
            'destination' => (string) ($this->extractValue($result, ['destination']) ?? $destination),
            'output' => (string) ($this->extractValue($result, ['output']) ?? ''),
        ];
    }

    /**
     * @param array<string, string> $dependencies
     *
     * @return array{dependencies:list<string>}
     */
    private function analyzeDependencies(array $dependencies): array
    {
        if (!method_exists($this->dependencyManager, 'analyzeDependencies')) {
            return ['dependencies' => $this->formatDependencies($dependencies)];
        }

        $analysis = $this->dependencyManager->analyzeDependencies($dependencies);

        if (!is_array($analysis)) {
            return ['dependencies' => $this->formatDependencies($dependencies)];
        }

        $rawDependencies = $analysis['dependencies'] ?? $dependencies;

        if (!is_array($rawDependencies)) {
            return ['dependencies' => $this->formatDependencies($dependencies)];
        }

        return ['dependencies' => $this->normalizeDependencyList($rawDependencies)];
    }

    /**
     * @return array{success:bool,error:?string}
     */
    private function installDependencies(string $pluginDirectory, string $packageName): array
    {
        if (method_exists($this->dependencyManager, 'addPathRepository')) {
            $result = $this->normalizeOperationResult(
                $this->dependencyManager->addPathRepository($pluginDirectory),
                'Failed to add path repository.',
            );

            if (!$result['success']) {
                return $result;
            }
        }

        if (method_exists($this->dependencyManager, 'requirePackage')) {
            $result = $this->normalizeOperationResult(
                $this->dependencyManager->requirePackage($packageName, '*'),
                sprintf('Failed to require package %s.', $packageName),
            );

            if (!$result['success']) {
                return $result;
            }
        }

        if (method_exists($this->dependencyManager, 'updatePackage')) {
            $result = $this->normalizeOperationResult(
                $this->dependencyManager->updatePackage($packageName),
                sprintf('Failed to update package %s.', $packageName),
            );

            if (!$result['success']) {
                return $result;
            }
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * @return array{success:bool,error:?string}
     */
    private function removeDependencies(string $packageName): array
    {
        if (method_exists($this->dependencyManager, 'removePackage')) {
            return $this->normalizeOperationResult(
                $this->dependencyManager->removePackage($packageName),
                sprintf('Failed to remove package %s.', $packageName),
            );
        }

        if (method_exists($this->dependencyManager, 'uninstallPackage')) {
            return $this->normalizeOperationResult(
                $this->dependencyManager->uninstallPackage($packageName),
                sprintf('Failed to uninstall package %s.', $packageName),
            );
        }

        return ['success' => true, 'error' => null];
    }

    private function refreshPluginSnapshot(): void
    {
        if (!method_exists($this->pluginManager, 'loadPlugins')) {
            throw new RuntimeException('Plugin manager must provide loadPlugins(string $directory).');
        }

        if (!method_exists($this->snapshotBuilder, 'build')) {
            throw new RuntimeException('Snapshot builder must provide build(array $pluginApis).');
        }

        $this->pluginManager->loadPlugins($this->pluginsDirectory);

        $apis = [];
        if (method_exists($this->pluginManager, 'collectApis')) {
            $collected = $this->pluginManager->collectApis();
            if (is_array($collected)) {
                $apis = $collected;
            }
        }

        $this->snapshotBuilder->build($apis);
    }

    /**
     * @return array{success:bool,error:?string}
     */
    private function normalizeOperationResult(mixed $result, string $defaultError): array
    {
        if (is_bool($result)) {
            return [
                'success' => $result,
                'error' => $result ? null : $defaultError,
            ];
        }

        if (is_array($result)) {
            $success = (bool) ($result['success'] ?? false);

            return [
                'success' => $success,
                'error' => $success ? null : (string) ($result['error'] ?? $defaultError),
            ];
        }

        if (is_object($result)) {
            $success = (bool) ($result->success ?? false);
            $error = $result->error ?? null;

            return [
                'success' => $success,
                'error' => $success ? null : (is_string($error) ? $error : $defaultError),
            ];
        }

        if ($result === null) {
            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => $defaultError];
    }

    /**
     * @return array{success:bool,output:string,exit_code:int}
     */
    private function runComposerInstall(): array
    {
        return ($this->commandRunner)('composer install --no-scripts');
    }

    /**
     * @return array{success:bool,output:string,exit_code:int}
     */
    private function runCommand(string $command): array
    {
        $output = [];
        $exitCode = 0;

        exec('cd ' . escapeshellarg($this->projectRoot) . ' && ' . $command . ' 2>&1', $output, $exitCode);

        return [
            'success' => $exitCode === 0,
            'output' => trim(implode(PHP_EOL, $output)),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractPluginDependencies(string $pluginDirectory): array
    {
        $composerFile = $pluginDirectory . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerFile)) {
            return [];
        }

        $content = file_get_contents($composerFile);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        $requires = $decoded['require'] ?? [];
        if (!is_array($requires)) {
            return [];
        }

        $dependencies = [];
        foreach ($requires as $package => $constraint) {
            $package = trim((string) $package);
            if ($package === '' || $package === 'php' || str_starts_with($package, 'ext-')) {
                continue;
            }

            $dependencies[$package] = trim((string) $constraint);
        }

        ksort($dependencies);

        return $dependencies;
    }

    private function resolvePackageName(string $pluginDirectory, string $pluginId): string
    {
        $composerFile = $pluginDirectory . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerFile)) {
            return $pluginId;
        }

        $content = file_get_contents($composerFile);
        if (!is_string($content) || trim($content) === '') {
            return $pluginId;
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $pluginId;
        }

        $packageName = trim((string) ($decoded['name'] ?? ''));

        return $packageName !== '' ? $packageName : $pluginId;
    }

    /**
     * @param array<string, string> $dependencies
     *
     * @return list<string>
     */
    private function formatDependencies(array $dependencies): array
    {
        return $this->normalizeDependencyList($dependencies);
    }

    /**
     * @param array<int|string, mixed> $rawDependencies
     *
     * @return list<string>
     */
    private function normalizeDependencyList(array $rawDependencies): array
    {
        $normalized = [];

        foreach ($rawDependencies as $name => $constraint) {
            if (is_array($constraint)) {
                $depName = trim((string) ($constraint['name'] ?? ''));
                $depConstraint = trim((string) ($constraint['constraint'] ?? ''));

                if ($depName === '') {
                    continue;
                }

                $normalized[] = $depConstraint === ''
                    ? $depName
                    : sprintf('%s:%s', $depName, $depConstraint);

                continue;
            }

            $dependencyName = is_string($name) ? trim($name) : '';
            if ($dependencyName !== '') {
                $depConstraint = trim((string) $constraint);
                $normalized[] = $depConstraint === ''
                    ? $dependencyName
                    : sprintf('%s:%s', $dependencyName, $depConstraint);

                continue;
            }

            $value = trim((string) $constraint);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    private function isSafePluginId(string $pluginId): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $pluginId) === 1;
    }

    private function composerJsonFile(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
    }

    private function composerLockFile(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.lock';
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if ($directory === '') {
            return;
        }

        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $directory));
        }
    }

    private function resolvePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            throw new RuntimeException('Path cannot be empty.');
        }

        if ($this->isAbsolutePath($trimmed)) {
            return rtrim($trimmed, DIRECTORY_SEPARATOR);
        }

        return rtrim($this->projectRoot . DIRECTORY_SEPARATOR . ltrim($trimmed, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }

    private function deleteDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
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

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException(sprintf('Cannot copy non-directory source: %s', $source));
        }

        $this->deleteDirectory($destination);
        $this->ensureDirectoryExists($destination);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source));
            $target = $destination . $relative;

            if ($item->isDir()) {
                $this->ensureDirectoryExists($target);
                continue;
            }

            $parent = dirname($target);
            $this->ensureDirectoryExists($parent);

            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException(sprintf('Failed to copy %s to %s', $item->getPathname(), $target));
            }
        }
    }

    private function extractValue(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }

            if (is_object($source) && isset($source->{$key})) {
                return $source->{$key};
            }

            if (is_object($source) && method_exists($source, $key)) {
                return $source->{$key}();
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @return array<string, mixed>
     */
    private function failureResult(
        string $error,
        bool $requiresConfirmation = false,
        array $dependencies = [],
        bool $rollbackPerformed = true,
    ): array {
        return [
            'success' => false,
            'error' => $error,
            'requires_confirmation' => $requiresConfirmation,
            'dependencies' => array_values($dependencies),
            'plugin_id' => null,
            'plugin_dir' => null,
            'rollback_performed' => $rollbackPerformed,
        ];
    }
}
