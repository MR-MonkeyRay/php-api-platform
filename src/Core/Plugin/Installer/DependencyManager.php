<?php

declare(strict_types=1);

namespace App\Core\Plugin\Installer;

use InvalidArgumentException;
use RuntimeException;

final class DependencyManager
{
    private readonly string $workingDirectory;

    /**
     * @var null|\Closure(array<int, string>, string): array{exit_code:int,stdout:string,stderr:string}
     */
    private readonly ?\Closure $commandRunner;

    /**
     * @param null|callable(array<int, string>, string): array{exit_code:int,stdout:string,stderr:string} $commandRunner
     */
    public function __construct(
        private readonly string $composerJsonPath,
        ?callable $commandRunner = null,
    ) {
        $resolvedPath = $this->resolveComposerJsonPath($composerJsonPath);
        $this->workingDirectory = dirname($resolvedPath);
        $this->commandRunner = $commandRunner === null ? null : \Closure::fromCallable($commandRunner);
    }

    /**
     * @param array<int|string, string> $pluginDeps
     *
     * @return array{
     *   dependencies:list<array{package:string,constraint:string,is_platform:bool}>,
     *   package_dependencies:list<array{package:string,constraint:string}>,
     *   platform_dependencies:list<array{package:string,constraint:string}>,
     *   requires_confirmation:bool
     * }
     */
    public function analyzeDependencies(array $pluginDeps): array
    {
        $dependencies = [];
        $packageDependencies = [];
        $platformDependencies = [];

        foreach ($pluginDeps as $name => $constraint) {
            [$package, $versionConstraint] = $this->normalizeDependency($name, $constraint);
            if ($package === '' || !$this->isValidDependencyName($package)) {
                continue;
            }

            $isPlatform = $this->isPlatformDependency($package);
            $dependency = [
                'package' => $package,
                'constraint' => $versionConstraint,
                'is_platform' => $isPlatform,
            ];
            $dependencies[] = $dependency;

            $summary = [
                'package' => $package,
                'constraint' => $versionConstraint,
            ];

            if ($isPlatform) {
                $platformDependencies[] = $summary;
                continue;
            }

            $packageDependencies[] = $summary;
        }

        usort(
            $dependencies,
            static fn (array $left, array $right): int => strcmp($left['package'], $right['package']),
        );
        usort(
            $packageDependencies,
            static fn (array $left, array $right): int => strcmp($left['package'], $right['package']),
        );
        usort(
            $platformDependencies,
            static fn (array $left, array $right): int => strcmp($left['package'], $right['package']),
        );

        return [
            'dependencies' => $dependencies,
            'package_dependencies' => $packageDependencies,
            'platform_dependencies' => $platformDependencies,
            'requires_confirmation' => $packageDependencies !== [],
        ];
    }

    /**
     * @return array{changed:bool,repositories:list<array<string, mixed>>}
     */
    public function addPathRepository(string $pluginPath): array
    {
        $pluginPath = trim($pluginPath);
        if ($pluginPath === '') {
            throw new InvalidArgumentException('Plugin path is required.');
        }

        $composer = $this->readComposerJson();
        $repositories = $composer['repositories'] ?? [];
        if (!is_array($repositories)) {
            $repositories = [];
        }

        $normalizedTarget = $this->normalizePath($pluginPath);
        $exists = false;

        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            $type = strtolower(trim((string) ($repository['type'] ?? '')));
            $url = trim((string) ($repository['url'] ?? ''));
            if ($type !== 'path' || $url === '') {
                continue;
            }

            if ($this->normalizePath($url) === $normalizedTarget) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $repositories[] = [
                'type' => 'path',
                'url' => $pluginPath,
                'options' => [
                    'symlink' => false,
                ],
            ];

            $composer['repositories'] = array_values(array_filter($repositories, static fn (mixed $item): bool => is_array($item)));
            $this->writeComposerJson($composer);
        }

        return [
            'changed' => !$exists,
            'repositories' => array_values(array_filter($repositories, static fn (mixed $item): bool => is_array($item))),
        ];
    }

    /**
     * @return list<string>
     */
    public function buildRequireCommand(string $packageName, string $constraint = '*'): array
    {
        $packageName = $this->normalizePackageName($packageName);
        $constraint = $this->normalizeConstraint($constraint);

        return [
            'composer',
            'require',
            '--no-update',
            sprintf('%s:%s', $packageName, $constraint),
        ];
    }

    /**
     * @return list<string>
     */
    public function buildUpdateCommand(string $packageName): array
    {
        $packageName = $this->normalizePackageName($packageName);

        return [
            'composer',
            'update',
            '--no-scripts',
            $packageName,
        ];
    }

    /**
     * @return array{success:bool,exit_code:int,command:list<string>,command_string:string,output:string,error_output:string}
     */
    public function requirePackage(string $packageName, string $constraint = '*'): array
    {
        return $this->requirePackageNoUpdate($packageName, $constraint);
    }

    /**
     * @return array{success:bool,exit_code:int,command:list<string>,command_string:string,output:string,error_output:string}
     */
    public function requirePackageNoUpdate(string $packageName, string $constraint = '*'): array
    {
        $command = $this->buildRequireCommand($packageName, $constraint);

        return $this->executeCommand($command);
    }

    /**
     * @return array{success:bool,exit_code:int,command:list<string>,command_string:string,output:string,error_output:string}
     */
    public function updatePackage(string $packageName): array
    {
        $command = $this->buildUpdateCommand($packageName);

        return $this->executeCommand($command);
    }

    /**
     * @return list<string>
     */
    public function buildRemoveCommand(string $packageName): array
    {
        $packageName = $this->normalizePackageName($packageName);

        return [
            'composer',
            'remove',
            '--no-update',
            $packageName,
        ];
    }

    /**
     * @return array{success:bool,exit_code:int,command:list<string>,command_string:string,output:string,error_output:string}
     */
    public function removePackage(string $packageName): array
    {
        $removeCommand = $this->buildRemoveCommand($packageName);
        $removeResult = $this->executeCommand($removeCommand);
        if (!$removeResult['success']) {
            return $removeResult;
        }

        $updateResult = $this->updatePackage($packageName);

        return [
            'success' => $updateResult['success'],
            'exit_code' => $updateResult['exit_code'],
            'command' => $updateResult['command'],
            'command_string' => $removeResult['command_string'] . ' && ' . $updateResult['command_string'],
            'output' => trim($removeResult['output'] . PHP_EOL . $updateResult['output']),
            'error_output' => trim($removeResult['error_output'] . PHP_EOL . $updateResult['error_output']),
        ];
    }

    /**
     * @return array{success:bool,exit_code:int,command:list<string>,command_string:string,output:string,error_output:string}
     */
    public function uninstallPackage(string $packageName): array
    {
        return $this->removePackage($packageName);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function normalizeDependency(int|string $name, string $constraint): array
    {
        if (is_int($name)) {
            $entry = trim($constraint);
            if ($entry === '') {
                return ['', '*'];
            }

            $parts = explode(':', $entry, 2);
            $package = trim($parts[0]);
            $versionConstraint = isset($parts[1]) ? trim($parts[1]) : '*';

            return [$package, $this->normalizeConstraint($versionConstraint)];
        }

        return [trim($name), $this->normalizeConstraint($constraint)];
    }

    private function isValidDependencyName(string $packageName): bool
    {
        if ($this->isPlatformDependency($packageName)) {
            return true;
        }

        return preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $packageName) === 1;
    }

    private function isPlatformDependency(string $packageName): bool
    {
        $normalized = strtolower($packageName);

        return $normalized === 'php'
            || str_starts_with($normalized, 'ext-')
            || str_starts_with($normalized, 'lib-')
            || $normalized === 'composer'
            || $normalized === 'composer-plugin-api'
            || $normalized === 'composer-runtime-api';
    }

    private function normalizePackageName(string $packageName): string
    {
        $packageName = trim($packageName);
        if ($packageName === '') {
            throw new InvalidArgumentException('Package name is required.');
        }

        if (preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $packageName) !== 1) {
            throw new InvalidArgumentException('Package name must use vendor/package format.');
        }

        return strtolower($packageName);
    }

    private function normalizeConstraint(string $constraint): string
    {
        $constraint = trim($constraint);

        return $constraint === '' ? '*' : $constraint;
    }

    /**
     * @param list<string> $command
     *
     * @return array{success:bool,exit_code:int,command:list<string>,command_string:string,output:string,error_output:string}
     */
    private function executeCommand(array $command): array
    {
        $result = $this->runCommand($command);
        $output = trim($result['stdout'] . PHP_EOL . $result['stderr']);

        return [
            'success' => $result['exit_code'] === 0,
            'exit_code' => $result['exit_code'],
            'command' => $command,
            'command_string' => $this->stringifyCommand($command),
            'output' => $output,
            'error_output' => trim($result['stderr']),
        ];
    }

    /**
     * @param list<string> $command
     *
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command): array
    {
        if ($this->commandRunner instanceof \Closure) {
            return ($this->commandRunner)($command, $this->workingDirectory);
        }

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($this->stringifyCommand($command), $descriptorSpec, $pipes, $this->workingDirectory);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start composer process.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param list<string> $command
     */
    private function stringifyCommand(array $command): string
    {
        return implode(' ', array_map(static fn (string $segment): string => escapeshellarg($segment), $command));
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(): array
    {
        $path = $this->resolveComposerJsonPath($this->composerJsonPath);
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('composer.json not found: %s', $path));
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('composer.json is empty or unreadable.');
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('composer.json root must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function writeComposerJson(array $composer): void
    {
        $path = $this->resolveComposerJsonPath($this->composerJsonPath);
        $encoded = json_encode(
            $composer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        if (!is_string($encoded)) {
            throw new RuntimeException('Failed to encode composer.json payload.');
        }

        if (file_put_contents($path, $encoded . PHP_EOL) === false) {
            throw new RuntimeException(sprintf('Failed to write composer.json: %s', $path));
        }
    }

    private function resolveComposerJsonPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('composer.json path is required.');
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}
