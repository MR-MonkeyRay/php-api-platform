<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;

final class OperationsDocTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = dirname(__DIR__, 2);
    }

    public function testOperationsDocumentExists(): void
    {
        self::assertFileExists($this->projectDir . '/docs/guides/operations.md');
    }

    public function testEnvExampleContainsAllRequiredVars(): void
    {
        $envExample = (string) file_get_contents($this->projectDir . '/.env.example');
        $operationsDoc = (string) file_get_contents($this->projectDir . '/docs/guides/operations.md');

        $requiredVars = [
            'DB_CONNECTION',
            'ADMIN_USERNAME',
            'ADMIN_PASSWORD_HASH',
            'API_KEY_PEPPER',
        ];

        foreach ($requiredVars as $var) {
            self::assertStringContainsString($var, $envExample);
            self::assertStringContainsString($var, $operationsDoc);
        }
    }

    public function testNginxExampleIncluded(): void
    {
        $operationsDoc = (string) file_get_contents($this->projectDir . '/docs/guides/operations.md');

        self::assertStringContainsString('allow', $operationsDoc);
        self::assertStringContainsString('limit_req', $operationsDoc);
    }

    public function testSecurityChecklistExists(): void
    {
        $operationsDoc = (string) file_get_contents($this->projectDir . '/docs/guides/operations.md');

        self::assertStringContainsString('Security', $operationsDoc);
        self::assertStringContainsString('Checklist', $operationsDoc);
    }
}
