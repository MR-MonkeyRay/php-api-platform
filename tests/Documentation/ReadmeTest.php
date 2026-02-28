<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = dirname(__DIR__, 2);
    }

    public function testReadmeExists(): void
    {
        self::assertFileExists($this->projectDir . '/README.md');
    }

    public function testReadmeHasRequiredSections(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/README.md');

        $requiredSections = [
            '# ',
            '## Features',
            '## Quick Start',
            '## Config',
            '## FAQ',
            '## License',
        ];

        foreach ($requiredSections as $section) {
            self::assertStringContainsString($section, $content);
        }
    }

    public function testQuickStartUsesDockerComposeCommands(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/README.md');

        self::assertStringContainsString('docker compose up -d --build', $content);
        self::assertStringContainsString('docker compose exec app composer install', $content);
        self::assertStringContainsString('docker compose exec app php bin/migrate', $content);
        self::assertStringContainsString('curl -i http://127.0.0.1:8080/health', $content);
    }

    public function testEnvVariablesDocumented(): void
    {
        $envExample = (string) file_get_contents($this->projectDir . '/.env.example');
        $readme = (string) file_get_contents($this->projectDir . '/README.md');

        preg_match_all('/^([A-Z_]+)=/m', $envExample, $matches);
        $envVars = $matches[1] ?? [];

        foreach ($envVars as $var) {
            self::assertStringContainsString($var, $readme, sprintf('Env var %s should be documented', $var));
        }
    }
}
