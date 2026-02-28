<?php

declare(strict_types=1);

namespace App\Core\Plugin\Installer;

use Psr\Log\LoggerInterface;
use RuntimeException;

final class PluginDownloader
{
    public function __construct(
        private readonly string $workspace,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    public function download(string $repositoryUrl, string $ref, string $destination): DownloadResult
    {
        $destination = $this->resolveDestination($destination);
        if (!$this->isDestinationSafe($destination)) {
            return DownloadResult::failure($destination, 'Destination path is outside allowed workspace.');
        }

        $this->deleteDirectory($destination);

        $parentDirectory = dirname($destination);
        if (!is_dir($parentDirectory) && !mkdir($parentDirectory, 0755, true) && !is_dir($parentDirectory)) {
            return DownloadResult::failure($destination, 'Failed to create destination parent directory.');
        }

        $command = sprintf(
            'git clone --depth 1 --branch %s %s %s 2>&1',
            escapeshellarg($ref),
            escapeshellarg($repositoryUrl),
            escapeshellarg($destination),
        );

        $result = $this->runCommandWithTimeout($command, $this->timeoutSeconds);
        if ($result['timed_out']) {
            $this->deleteDirectory($destination);

            return DownloadResult::failure(
                $destination,
                sprintf('Download timeout after %d seconds.', $this->timeoutSeconds),
                $result['output'],
            );
        }

        if ($result['exit_code'] !== 0) {
            $this->deleteDirectory($destination);

            return DownloadResult::failure(
                $destination,
                'git clone failed: ' . trim($result['output']),
                $result['output'],
            );
        }

        $securityCheck = $this->validateDownloadedPlugin($destination);
        if ($securityCheck !== null) {
            $this->deleteDirectory($destination);

            return DownloadResult::failure($destination, $securityCheck, $result['output']);
        }

        $this->logger->info('Plugin downloaded successfully.', [
            'repository' => $repositoryUrl,
            'ref' => $ref,
            'destination' => $destination,
        ]);

        return DownloadResult::success($destination, $result['output']);
    }

    private function resolveDestination(string $destination): string
    {
        $destination = trim($destination);
        if ($destination === '') {
            throw new RuntimeException('Destination cannot be empty.');
        }

        if ($this->isAbsolutePath($destination)) {
            return $this->normalizeAbsolutePath($destination);
        }

        return $this->normalizeAbsolutePath(
            rtrim($this->workspace, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($destination, DIRECTORY_SEPARATOR),
        );
    }

    private function isDestinationSafe(string $destination): bool
    {
        $workspace = $this->canonicalPath($this->workspace);
        if ($workspace === null) {
            return false;
        }

        $workspaceAbsolute = $this->normalizeAbsolutePath($workspace);
        $destinationAbsolute = $this->normalizeAbsolutePath($this->absolutePath($destination));
        $workspacePrefix = rtrim($workspaceAbsolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($destinationAbsolute . DIRECTORY_SEPARATOR, $workspacePrefix);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
    }

    private function absolutePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return rtrim($this->workspace, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function canonicalPath(string $path): ?string
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            return null;
        }

        $resolved = realpath($path);

        return is_string($resolved) ? $resolved : null;
    }

    /**
     * @return array{exit_code:int,timed_out:bool,output:string}
     */
    private function runCommandWithTimeout(string $command, int $timeoutSeconds): array
    {
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor, $pipes, $this->workspace);
        if (!is_resource($process)) {
            return [
                'exit_code' => 1,
                'timed_out' => false,
                'output' => 'Unable to start process.',
            ];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $startAt = microtime(true);

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $startAt) >= $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(100000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = trim($stdout . PHP_EOL . $stderr);

        return [
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'output' => $output,
        ];
    }

    private function validateDownloadedPlugin(string $destination): ?string
    {
        $pluginJson = $destination . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($pluginJson)) {
            return 'plugin.json not found in downloaded plugin.';
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                return 'Symbolic links are not allowed in plugin package.';
            }

            $path = $item->getPathname();
            if (!$this->isPathInside($path, $destination)) {
                return 'Path traversal detected in downloaded plugin package.';
            }
        }

        return null;
    }

    private function isPathInside(string $path, string $root): bool
    {
        $rootNormalized = rtrim($this->normalizeAbsolutePath($root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $pathNormalized = rtrim($this->normalizeAbsolutePath($path), DIRECTORY_SEPARATOR);

        return str_starts_with($pathNormalized . DIRECTORY_SEPARATOR, $rootNormalized);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $normalizedInput = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        $prefix = '';
        if (str_starts_with($normalizedInput, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
        } elseif (preg_match('#^[A-Za-z]:#', $normalizedInput) === 1) {
            $prefix = substr($normalizedInput, 0, 2);
            $normalizedInput = substr($normalizedInput, 2);
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $normalizedInput) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $joined = implode(DIRECTORY_SEPARATOR, $segments);

        if ($prefix === DIRECTORY_SEPARATOR) {
            return $joined === '' ? DIRECTORY_SEPARATOR : DIRECTORY_SEPARATOR . $joined;
        }

        if ($prefix !== '') {
            return $joined === '' ? $prefix . DIRECTORY_SEPARATOR : $prefix . DIRECTORY_SEPARATOR . $joined;
        }

        return $joined;
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
