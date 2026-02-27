<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Logger;

use App\Core\Logger\LoggerFactory;
use DateTimeImmutable;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerFactoryTest extends TestCase
{
    public function testLoggerCreatesJsonFormat(): void
    {
        $factory = new LoggerFactory('info');
        $logger = $factory->create('test');

        self::assertInstanceOf(Logger::class, $logger);

        $formatter = $this->resolveFormatter($logger);
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-02-27T00:00:00+00:00'),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: ['key' => 'value'],
            extra: ['trace_id' => 'trace-abc-123'],
        );

        $entry = json_decode(
            trim($formatter->format($record)),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        self::assertArrayHasKey('timestamp', $entry);
        self::assertSame('INFO', $entry['level']);
        self::assertSame('Test message', $entry['message']);
        self::assertSame(['key' => 'value'], $entry['context']);
        self::assertSame('trace-abc-123', $entry['trace_id']);
    }

    public function testLoggerCreatesStderrFilterForErrorAndAbove(): void
    {
        $factory = new LoggerFactory();
        $logger = $factory->create('test');

        self::assertInstanceOf(Logger::class, $logger);

        $handlers = $logger->getHandlers();
        self::assertNotEmpty($handlers);
        self::assertInstanceOf(FilterHandler::class, $handlers[0]);

        $acceptedNames = array_map(
            static fn (Level $level): string => $level->getName(),
            $handlers[0]->getAcceptedLevels(),
        );

        self::assertContains('ERROR', $acceptedNames);
        self::assertContains('EMERGENCY', $acceptedNames);
        self::assertNotContains('WARNING', $acceptedNames);
        self::assertInstanceOf(StreamHandler::class, $handlers[0]->getHandler());
    }

    public function testLoggerCopiesTraceIdFromContextIntoExtra(): void
    {
        $factory = new LoggerFactory();
        $logger = $factory->create('test');

        self::assertInstanceOf(Logger::class, $logger);

        $capture = new TestHandler(Level::Debug);
        $logger->setHandlers([$capture]);

        $logger->info('message', ['trace_id' => 'trace-in-context']);

        $records = $capture->getRecords();
        self::assertCount(1, $records);
        self::assertSame('trace-in-context', $records[0]->context['trace_id'] ?? null);
        self::assertSame('trace-in-context', $records[0]->extra['trace_id'] ?? null);
    }

    private function resolveFormatter(Logger $logger): FormatterInterface
    {
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                return $handler->getFormatter();
            }

            if ($handler instanceof FilterHandler) {
                $innerHandler = $handler->getHandler();
                if ($innerHandler instanceof FormattableHandlerInterface) {
                    return $innerHandler->getFormatter();
                }
            }
        }

        self::fail('No stream handler formatter found.');
    }
}
