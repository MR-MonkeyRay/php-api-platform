<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;

final class DockerDeploymentGuideTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = dirname(__DIR__, 2);
    }

    public function testGuideExists(): void
    {
        self::assertFileExists($this->projectDir . '/docs/guides/docker-deployment.md');
    }

    public function testHealthCheckDocumented(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/docker-deployment.md');

        self::assertStringContainsStringIgnoringCase('health', $content);
    }

    public function testTroubleshootingSection(): void
    {
        $content = (string) file_get_contents($this->projectDir . '/docs/guides/docker-deployment.md');

        self::assertStringContainsStringIgnoringCase('troubleshooting', $content);
    }
}
