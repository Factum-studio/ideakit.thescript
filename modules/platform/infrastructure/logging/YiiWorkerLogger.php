<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\logging;

use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\enum\CriticalWorkerStopReason;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Ramsey\Uuid\Uuid;
use Stringable;
use yii\log\Dispatcher;
use yii\log\Logger;

final class YiiWorkerLogger extends AbstractLogger
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
            'critical_worker.started', 'critical_worker.rejected', 'critical_worker.failed', 'critical_worker.stopped',
        ], true)) {
            return;
        }
        $safe = ['event' => $message];
        $reason = $context['reason'] ?? null;
        if (is_string($reason) && (
            CriticalWorkerError::tryFrom($reason) !== null
            || CriticalWorkerStopReason::tryFrom($reason) !== null
            || in_array($reason, ['invalid_envelope', 'handler_rejected'], true)
        )) {
            $safe['reason'] = $reason;
        }
        if (is_bool($context['cleanup_failed'] ?? null)) {
            $safe['cleanup_failed'] = $context['cleanup_failed'];
        }
        foreach (['outbox_id', 'correlation_id'] as $field) {
            $id = $context[$field] ?? null;
            if (is_string($id) && strlen($id) === 36 && Uuid::isValid($id) && Uuid::fromString($id)->toString() === $id) {
                $safe[$field] = $id;
            }
        }
        $this->logger->log($safe, $severity, 'platform.critical_worker');
        $this->logger->flush(true);
    }
}
