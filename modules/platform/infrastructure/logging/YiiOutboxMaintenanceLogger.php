<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\logging;

use modules\platform\application\enum\OutboxMaintenanceError;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use yii\log\Dispatcher;
use yii\log\Logger;

final class YiiOutboxMaintenanceLogger extends AbstractLogger
{
    private readonly Logger $logger;

    public function __construct(Dispatcher $dispatcher)
    {
        $this->logger = new Logger(['dispatcher' => $dispatcher, 'traceLevel' => 0, 'flushInterval' => 0]);
    }

    /**
     * @param array<string, mixed> $context
     * @throws InvalidArgumentException
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $severity = match ($level) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR => Logger::LEVEL_ERROR,
            LogLevel::WARNING => Logger::LEVEL_WARNING,
            LogLevel::NOTICE, LogLevel::INFO => Logger::LEVEL_INFO,
            LogLevel::DEBUG => Logger::LEVEL_TRACE,
            default => throw new InvalidArgumentException('invalid_log_level'),
        };
        if (!is_string($message) || !in_array($message, [
            'platform.outbox_maintenance.recovered',
            'platform.outbox_maintenance.payload_cleared',
            'platform.outbox_maintenance.failed',
        ], true)) {
            return;
        }

        $safe = ['event' => $message];
        $fields = match ($message) {
            'platform.outbox_maintenance.recovered' => ['retry_scheduled', 'failed'],
            'platform.outbox_maintenance.payload_cleared' => ['cleared'],
            default => [],
        };
        foreach ($fields as $field) {
            $count = $context[$field] ?? null;
            if (is_int($count) && $count >= 0 && $count <= 100) {
                $safe[$field] = $count;
            }
        }
        if ($message === 'platform.outbox_maintenance.failed') {
            $operation = $context['operation'] ?? null;
            if (is_string($operation) && in_array($operation, ['recover', 'clear_payload', 'status'], true)) {
                $safe['operation'] = $operation;
            }
            $reason = $context['reason'] ?? null;
            if (is_string($reason) && OutboxMaintenanceError::tryFrom($reason) !== null) {
                $safe['reason'] = $reason;
            }
        }

        $this->logger->log($safe, $severity, 'platform.outbox_maintenance');
        $this->logger->flush(true);
    }
}
