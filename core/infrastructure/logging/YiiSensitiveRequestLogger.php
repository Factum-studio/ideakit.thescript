<?php

declare(strict_types=1);

namespace core\infrastructure\logging;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Ramsey\Uuid\Uuid;
use Stringable;
use yii\log\Dispatcher;
use yii\log\Logger;

final class YiiSensitiveRequestLogger extends AbstractLogger
{
    /** @param list<string> $reasons */
    public function __construct(private readonly Dispatcher $dispatcher, private readonly array $reasons)
    {
    }

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $severity = match ($level) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR => Logger::LEVEL_ERROR,
            LogLevel::WARNING => Logger::LEVEL_WARNING,
            LogLevel::NOTICE, LogLevel::INFO => Logger::LEVEL_INFO,
            LogLevel::DEBUG => Logger::LEVEL_TRACE,
            default => throw new InvalidArgumentException('invalid_log_level'),
        };
        if ($message !== 'sensitive_request.failed') {
            return;
        }

        $safe = ['event' => $message];
        $reason = $context['reason'] ?? null;
        if (is_string($reason) && in_array($reason, $this->reasons, true)
            && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) === 1
        ) {
            $safe['reason'] = $reason;
        }
        $status = $context['http_status'] ?? null;
        if (in_array($status, [400, 403, 405, 413, 415, 500, 503], true)) {
            $safe['http_status'] = $status;
        }
        $id = $context['correlation_id'] ?? null;
        if (is_string($id) && strlen($id) === 36 && Uuid::isValid($id) && Uuid::fromString($id)->toString() === $id) {
            $safe['correlation_id'] = $id;
        }

        // Dispatcher converts target failures to verbose exceptions for other targets.
        // Collect directly so the owning error boundary can handle a sink failure safely.
        foreach ($this->dispatcher->targets as $target) {
            if (!$target->enabled) {
                continue;
            }
            $logVars = $target->logVars;
            $prefix = $target->prefix;
            $messages = $target->messages;
            $exportInterval = $target->exportInterval;
            try {
                $target->logVars = [];
                $target->prefix = static fn (): string => '';
                $target->messages = [];
                $target->collect([[$safe, $severity, 'application.sensitive_request', microtime(true), [], 0]], true);
            } finally {
                $target->logVars = $logVars;
                $target->prefix = $prefix;
                $target->messages = $messages;
                $target->exportInterval = $exportInterval;
            }
        }
    }
}
