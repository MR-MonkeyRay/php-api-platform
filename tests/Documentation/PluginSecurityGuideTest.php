<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;

final class PluginSecurityGuideTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = dirname(__DIR__, 2);
    }

    public function testGuideExists(): void
    {
        self::assertFileExists($this->projectDir . '/docs/guides/plugin-security.md');
    }

    public function testSecurityPrinciplesCovered(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/plugin-security.md');

        $requiredTopics = [
            'whitelist',
            'ref',
            'no-scripts',
            'rollback',
            'best practices',
        ];

        foreach ($requiredTopics as $topic) {
            self::assertStringContainsStringIgnoringCase($topic, $content);
        }
    }

    public function testWhitelistExampleIncluded(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/plugin-security.md');

        self::assertStringContainsString('PLUGIN_WHITELIST', $content);
    }
}
