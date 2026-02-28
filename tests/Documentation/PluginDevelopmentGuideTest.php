<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;

final class PluginDevelopmentGuideTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = dirname(__DIR__, 2);
    }

    public function testGuideExists(): void
    {
        self::assertFileExists($this->projectDir . '/docs/guides/plugin-development.md');
    }

    public function testGuideHasRequiredSections(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/plugin-development.md');

        $requiredSections = [
            'PluginInterface',
            'plugin.json',
            'routes',
            'ApiDefinition',
            '策略',
        ];

        foreach ($requiredSections as $section) {
            self::assertStringContainsStringIgnoringCase($section, $content);
        }
    }

    public function testCodeExamplesAreValidPhpSyntax(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/plugin-development.md');
        preg_match_all('/```php\n(.*?)```/s', $content, $matches);

        $snippets = $matches[1] ?? [];
        self::assertNotEmpty($snippets, 'Expected at least one PHP code block.');

        foreach ($snippets as $snippet) {
            $this->assertValidPhpSnippet((string) $snippet);
        }
    }

    public function testPluginJsonExampleIsValid(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/plugin-development.md');
        preg_match('/```json\n(\{.*?\})\n```/s', $content, $matches);

        self::assertNotEmpty($matches, 'Expected one JSON code block for plugin.json.');

        $json = json_decode((string) ($matches[1] ?? ''), true);

        self::assertIsArray($json);
        self::assertArrayHasKey('id', $json);
        self::assertArrayHasKey('mainClass', $json);
    }

    private function assertValidPhpSnippet(string $snippet): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'plugin-dev-doc-');
        self::assertNotFalse($tempFile);

        $phpFile = $tempFile . '.php';
        @unlink($tempFile);

        $wrapped = "<?php\n" . trim($snippet) . "\n";
        file_put_contents($phpFile, $wrapped);

        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($phpFile) . ' 2>&1', $output, $exitCode);

        @unlink($phpFile);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }
}
