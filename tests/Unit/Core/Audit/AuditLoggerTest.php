<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Audit;

use App\Core\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir() . '/php-api-platform-audit-logger-' . bin2hex(random_bytes(6)) . '.log';
        $_ENV['ADMIN_AUDIT_LOG_FILE'] = $this->logFile;
        putenv('ADMIN_AUDIT_LOG_FILE=' . $this->logFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }

        unset($_ENV['ADMIN_AUDIT_LOG_FILE']);
        putenv('ADMIN_AUDIT_LOG_FILE');

        parent::tearDown();
    }

    public function testWritesJsonlRecord(): void
    {
        $logger = AuditLogger::fromEnvironment();
        $logger->log('api_key.create', 'admin', ['kid' => 'abc123'], '127.0.0.1');

        self::assertFileExists($this->logFile);

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        $record = json_decode((string) $lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('api_key.create', $record['action']);
        self::assertSame('admin', $record['actor']);
        self::assertSame('127.0.0.1', $record['ip']);
        self::assertSame('abc123', $record['details']['kid']);
        self::assertArrayHasKey('timestamp', $record);
    }

    public function testFallsBackToUnknownIpWhenBlank(): void
    {
        $logger = AuditLogger::fromEnvironment();
        $logger->log('policy.upsert', 'system', ['api_id' => 'x'], '');

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        $record = json_decode((string) $lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('unknown', $record['ip']);
    }
}
