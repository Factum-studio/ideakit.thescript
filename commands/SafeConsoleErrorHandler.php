<?php

declare(strict_types=1);

namespace app\commands;

use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\exception\OutboxWriteException;
use RuntimeException;
use Throwable;
use Yii;
use yii\console\ErrorHandler;
use yii\log\Logger;

final class SafeConsoleErrorHandler extends ErrorHandler
{
    private const FALLBACK = "unexpected_failure cause_code=UNKNOWN\n";

    /** @param Throwable $exception */
    public function handleException($exception): void
    {
        // Console controllers can reset this flag after application configuration has been applied.
        $this->silentExitOnException = false;
        parent::handleException($exception);
    }

    /** @param Throwable $exception */
    public function logException($exception): void
    {
        $record = [
            'console.unhandled_failure ' . self::diagnostic($exception),
            Logger::LEVEL_ERROR,
            'application.console_failure',
            microtime(true),
            [],
        ];
        // Dispatcher converts target failures to verbose exceptions; collect directly to keep diagnostics private.
        foreach (Yii::$app->getLog()->targets as $target) {
            if (!$target->enabled) {
                continue;
            }
            try {
                $target->collect([$record], true);
            } catch (Throwable $failure) {
                // Shutdown must not retry this target through the verbose dispatcher fallback.
                $target->enabled = false;

                throw $failure;
            }
        }
    }

    /** @param Throwable $exception */
    protected function renderException($exception): void
    {
        $message = self::diagnostic($exception) . "\n";
        if (fwrite(STDERR, $message) !== strlen($message)) {
            throw new RuntimeException('diagnostic_unavailable');
        }
    }

    /**
     * @param Throwable $exception
     * @param Throwable $previousException
     */
    protected function handleFallbackExceptionMessage($exception, $previousException): never
    {
        try {
            fwrite(STDERR, self::FALLBACK);
        } catch (Throwable) {
            // A broken diagnostic stream cannot turn a failed process into success.
        }

        exit(1);
    }

    private static function diagnostic(Throwable $exception): string
    {
        if ($exception instanceof CriticalWorkerException
            || $exception instanceof OutboxRelayException
            || $exception instanceof OutboxMaintenanceException
        ) {
            return $exception->error->value . ' cause_code=' . $exception->causeCode->value;
        }
        if ($exception instanceof BrokerTransportException) {
            return $exception->errorCode->value . ' cause_code=' . SafeCauseCode::TRANSPORT->value;
        }
        if ($exception instanceof OutboxWriteException) {
            $cause = $exception->failure === OutboxWriteFailure::PERSISTENCE_FAILURE
                ? SafeCauseCode::PERSISTENCE : SafeCauseCode::UNKNOWN;

            return $exception->failure->value . ' cause_code=' . $cause->value;
        }

        return rtrim(self::FALLBACK, "\n");
    }
}
