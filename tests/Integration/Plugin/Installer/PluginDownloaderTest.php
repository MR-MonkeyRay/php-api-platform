<?php

declare(strict_types=1);

namespace Tests\Integration\Plugin\Installer;

use App\Core\Plugin\Installer\PluginDownloader;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class PluginDownloaderTest extends TestCase
{
    private string $workspace;
    private string $remoteRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->workspace = sys_get_temp_dir() . '/plugin-downloader-workspace-' . $suffix;
        $this->remoteRepo = sys_get_temp_dir() . '/plugin-downloader-remote-' . $suffix;

        self::assertTrue(mkdir($this->workspace, 0755, true) || is_dir($this->workspace));
        self::assertTrue(mkdir($this->remoteRepo, 0755, true) || is_dir($this->remoteRepo));

        $this->initializeRemoteRepository($this->remoteRepo);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workspace);
        $this->deleteDirectory($this->remoteRepo);

        parent::tearDown();
    }

    public function testDownloadPluginSuccess(): void
    {
        $downloader = new PluginDownloader($this->workspace, new Logger('test-downloader'), 30);

        $destination = $this->workspace . '/plugin-a';
        $result = $downloader->download($this->remoteRepo, 'v1.0.0', $destination);

        self::assertTrue($result->success);
        self::assertDirectoryExists($destination);
        self::assertFileExists($destination . '/plugin.json');
    }

    public function testDownloadFailureCleansDestinationDirectory(): void
    {
        $downloader = new PluginDownloader($this->workspace, new Logger('test-downloader'), 30);

        $destination = $this->workspace . '/plugin-fail';
        $result = $downloader->download($this->remoteRepo, 'v9.9.9', $destination);

        self::assertFalse($result->success);
        self::assertDirectoryDoesNotExist($destination);
    }

    public function testRejectsDestinationPathTraversal(): void
    {
        $downloader = new PluginDownloader($this->workspace, new Logger('test-downloader'), 30);

        $destination = '../outside-plugin';
        $result = $downloader->download($this->remoteRepo, 'v1.0.0', $destination);

        self::assertFalse($result->success);
        self::assertStringContainsString('outside', strtolower((string) $result->error));
    }

    public function testRejectsSymbolicLinksInDownloadedPlugin(): void
    {
        $repoWithSymlink = $this->createRepositoryWithSymlink();
        $downloader = new PluginDownloader($this->workspace, new Logger('test-downloader'), 30);

        $destination = $this->workspace . '/plugin-symlink';
        $result = $downloader->download($repoWithSymlink, 'v2.0.0', $destination);

        self::assertFalse($result->success);
        self::assertDirectoryDoesNotExist($destination);
        self::assertStringContainsString('symbolic', strtolower((string) $result->error));

        $this->deleteDirectory($repoWithSymlink);
    }

    private function initializeRemoteRepository(string $repository): void
    {
        $commands = [
            sprintf('git -C %s init --quiet', escapeshellarg($repository)),
            sprintf('git -C %s config user.email "tester@example.com"', escapeshellarg($repository)),
            sprintf('git -C %s config user.name "Test User"', escapeshellarg($repository)),
        ];

        foreach ($commands as $command) {
            $this->runCommand($command);
        }

        file_put_contents(
            $repository . '/plugin.json',
            (string) json_encode([
                'id' => 'plugin-a',
                'name' => 'Plugin A',
                'version' => '1.0.0',
                'mainClass' => 'PluginA',
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($repository . '/README.md', "test plugin\n");

        $this->runCommand(sprintf('git -C %s add .', escapeshellarg($repository)));
        $this->runCommand(sprintf('git -C %s commit -m "init" --quiet', escapeshellarg($repository)));
        $this->runCommand(sprintf('git -C %s tag v1.0.0', escapeshellarg($repository)));
    }

    private function createRepositoryWithSymlink(): string
    {
        $repo = sys_get_temp_dir() . '/plugin-downloader-symlink-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($repo, 0755, true) || is_dir($repo));

        $this->runCommand(sprintf('git -C %s init --quiet', escapeshellarg($repo)));
        $this->runCommand(sprintf('git -C %s config user.email "tester@example.com"', escapeshellarg($repo)));
        $this->runCommand(sprintf('git -C %s config user.name "Test User"', escapeshellarg($repo)));

        file_put_contents(
            $repo . '/plugin.json',
            (string) json_encode([
                'id' => 'plugin-symlink',
                'name' => 'Plugin symlink',
                'version' => '2.0.0',
                'mainClass' => 'PluginSymlink',
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($repo . '/target.txt', 'target');
        symlink('target.txt', $repo . '/bad-link');

        $this->runCommand(sprintf('git -C %s add .', escapeshellarg($repo)));
        $this->runCommand(sprintf('git -C %s commit -m "symlink" --quiet', escapeshellarg($repo)));
        $this->runCommand(sprintf('git -C %s tag v2.0.0', escapeshellarg($repo)));

        return $repo;
    }

    private function runCommand(string $command): void
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
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
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
                continue;
            }

            @rmdir($item->getPathname());
        }

        @rmdir($directory);
    }
}
