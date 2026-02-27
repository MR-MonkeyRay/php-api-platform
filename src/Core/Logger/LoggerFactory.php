<?php

declare(strict_types=1);

namespace App\Core\Logger;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stringable;

final class LoggerFactory
{
    public function __construct(
        private readonly string $minLevel = 'debug',
    ) {
    }

    public function create(string $channel = 'app'): LoggerInterface
    {
        $logger = new Logger($channel);
        $formatter = new TraceAwareJsonFormatter();

        $stdoutHandler = new StreamHandler('php://stdout', $this->resolveLevel($this->minLevel));
        $stdoutHandler->setFormatter($formatter);

        $stderrStreamHandler = new StreamHandler('php://stderr', Level::Error);
        $stderrStreamHandler->setFormatter($formatter);

        $stderrHandler = new FilterHandler($stderrStreamHandler, Level::Error, Level::Emergency);

        $logger->pushHandler($stdoutHandler);
        $logger->pushHandler($stderrHandler);

        $logger->pushProcessor(
            static function (LogRecord $record): LogRecord {
                if (isset($record->extra['trace_id']) && is_string($record->extra['trace_id']) && $record->extra['trace_id'] !== '') {
                    return $record;
                }

                if (isset($record->context['trace_id']) && is_string($record->context['trace_id']) && $record->context['trace_id'] !== '') {
                    return $record->with(extra: ['trace_id' => $record->context['trace_id']] + $record->extra);
                }

                return $record;
            }
        );

        return $logger;
    }

    private function resolveLevel(string $level): Level
    {
        return Logger::toMonologLevel($level);
    }
}

final class TraceAwareJsonFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_JSON, true, true);
    }

    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);

        $traceId = null;
        if (isset($normalized['extra']['trace_id']) && is_string($normalized['extra']['trace_id']) && $normalized['extra']['trace_id'] !== '') {
            $traceId = $normalized['extra']['trace_id'];
        } elseif (isset($normalized['context']['trace_id']) && is_string($normalized['context']['trace_id']) && $normalized['context']['trace_id'] !== '') {
            $traceId = $normalized['context']['trace_id'];
        }

        return [
            'timestamp' => $this->resolveTimestamp($normalized['datetime'] ?? null),
            'level' => $this->resolveLevelName($normalized['level_name'] ?? null),
            'message' => $this->resolveMessage($normalized['message'] ?? null),
            'context' => $this->resolveContext($normalized['context'] ?? null),
            'trace_id' => $traceId,
        ];
    }

    private function resolveTimestamp(mixed $datetime): ?string
    {
        return is_string($datetime) && $datetime !== '' ? $datetime : null;
    }

    private function resolveLevelName(mixed $level): ?string
    {
        return is_string($level) && $level !== '' ? $level : null;
    }

    private function resolveMessage(mixed $message): ?string
    {
        if (is_string($message)) {
            return $message;
        }

        if ($message instanceof Stringable) {
            return (string) $message;
        }

        return null;
    }

    private function resolveContext(mixed $context): array
    {
        return is_array($context) ? $context : [];
    }
}
