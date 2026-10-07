<?php

declare(strict_types=1);

namespace modules\platform\application\handler;

use Closure;
use modules\platform\application\command\RunCriticalWorkerCommand;
use modules\platform\application\dto\CriticalWorkerReceipt;
use modules\platform\application\dto\CriticalWorkerSettings;
use modules\platform\application\enum\BackgroundCommandOutcome;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\enum\CriticalWorkerStopReason;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\BackgroundCommandRejectedException;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\port\IBrokerReceiver;
use modules\platform\application\port\IWorkerExecutionGuard;
use modules\platform\application\port\IWorkerRuntime;
use modules\platform\application\route\BackgroundCommandRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

final class RunCriticalWorkerHandler
{
    public function __construct(
        private readonly IBrokerReceiver $receiver,
        private readonly BackgroundCommandRegistry $registry,
        private readonly IWorkerRuntime $runtime,
        private readonly IWorkerExecutionGuard $guard,
        private readonly CriticalWorkerSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws CriticalWorkerException */
    public function handle(RunCriticalWorkerCommand $command): CriticalWorkerReceipt
    {
        $received = $completed = $alreadyCompleted = $rejected = 0;
        $failure = null;
        $stopReason = null;

        try {
            $this->registry->assertCriticalRouteRegistered();
            $this->guard->assertClean();
            $this->runtime->start($command, $this->settings);
            $startedAt = $this->runtime->monotonicSeconds();

            while (($stopReason = $this->stopReason($command, $received, $startedAt)) === null) {
                $delivery = $message = $handler = $outcome = $rejection = null;

                try {
                    $this->bounded($this->settings->brokerOperationTimeoutSeconds, function () use (&$delivery): void {
                        $this->guard->assertClean();
                        $delivery = $this->receiver->receive((float) $this->settings->receiveTimeoutSeconds);
                    });
                    if ($delivery === null) {
                        continue;
                    }
                    $received++;
                    if ($this->runtime->shouldStop()) {
                        $stopReason = CriticalWorkerStopReason::SIGNAL;

                        break;
                    }

                    $this->bounded(
                        $this->settings->brokerOperationTimeoutSeconds,
                        function () use ($delivery, &$message, &$handler, &$rejection): void {
                            try {
                                $message = $delivery->message();
                            } catch (BrokerTransportException $exception) {
                                $rejection = match ($exception->errorCode) {
                                    BrokerTransportErrorCode::INVALID_ENVELOPE => 'invalid_envelope',
                                    BrokerTransportErrorCode::UNSUPPORTED_CONTRACT => 'unsupported_contract',
                                    default => throw $exception,
                                };

                                return;
                            }
                            try {
                                $handler = $this->registry->requireHandler($message->messageType, $message->schemaVersion);
                            } catch (CriticalWorkerException $exception) {
                                if ($exception->error !== CriticalWorkerError::UNSUPPORTED_CONTRACT) {
                                    throw $exception;
                                }
                                $rejection = 'unsupported_contract';
                            }
                        },
                    );
                    if ($this->runtime->shouldStop()) {
                        $stopReason = CriticalWorkerStopReason::SIGNAL;

                        break;
                    }
                    if ($rejection === null) {
                        if ($handler === null || $message === null) {
                            throw new CriticalWorkerException(CriticalWorkerError::HANDLER_FAILURE);
                        }
                        $this->bounded(
                            $this->settings->handlerTimeoutSeconds,
                            static function () use ($handler, $message, &$outcome, &$rejection): void {
                                try {
                                    $outcome = $handler->handle($message);
                                } catch (BackgroundCommandRejectedException) {
                                    $rejection = 'handler_rejected';
                                } catch (Throwable $exception) {
                                    throw new CriticalWorkerException(
                                        CriticalWorkerError::HANDLER_FAILURE,
                                        $exception instanceof BrokerTransportException ? null : $exception,
                                        SafeCauseCode::HANDLER,
                                    );
                                }
                            },
                        );
                    }
                    $this->bounded(
                        $this->settings->brokerOperationTimeoutSeconds,
                        function () use ($delivery, $outcome, $rejection): void {
                            $this->guard->assertClean();
                            if ($rejection !== null) {
                                $delivery->reject();
                                $this->logger->warning('critical_worker.rejected', ['reason' => $rejection]);
                            } else {
                                if ($outcome === null) {
                                    throw new CriticalWorkerException(CriticalWorkerError::HANDLER_FAILURE);
                                }
                                $delivery->acknowledge();
                            }
                        },
                    );
                    if ($rejection !== null) {
                        $rejected++;
                    } elseif ($outcome === BackgroundCommandOutcome::COMPLETED) {
                        $completed++;
                    } else {
                        $alreadyCompleted++;
                    }
                } finally {
                    unset($delivery, $message, $handler, $outcome, $rejection);
                }
            }
        } catch (CriticalWorkerException $exception) {
            $failure = $exception;
        } catch (Throwable $exception) {
            $failure = new CriticalWorkerException(
                $exception instanceof BrokerTransportException
                    ? match ($exception->errorCode) {
                        BrokerTransportErrorCode::CONFIGURATION_INVALID,
                        BrokerTransportErrorCode::TOPOLOGY_MISMATCH => CriticalWorkerError::CONFIGURATION_INVALID,
                        default => CriticalWorkerError::TRANSPORT_FAILURE,
                    } : CriticalWorkerError::UNEXPECTED_FAILURE,
                $exception instanceof BrokerTransportException ? null : $exception,
                $exception instanceof BrokerTransportException ? SafeCauseCode::TRANSPORT : SafeCauseCode::UNKNOWN,
            );
        } finally {
            $failure = $this->cleanup($failure);
        }

        if ($failure !== null) {
            throw $failure;
        }
        if ($stopReason === null) {
            throw new CriticalWorkerException(CriticalWorkerError::UNEXPECTED_FAILURE);
        }

        return new CriticalWorkerReceipt($received, $completed, $alreadyCompleted, $rejected, $stopReason);
    }

    private function stopReason(RunCriticalWorkerCommand $command, int $received, float $startedAt): ?CriticalWorkerStopReason
    {
        if ($this->runtime->shouldStop()) {
            return CriticalWorkerStopReason::SIGNAL;
        }
        if ($received >= $command->maxMessages) {
            return CriticalWorkerStopReason::MESSAGE_LIMIT;
        }
        if ($this->runtime->monotonicSeconds() - $startedAt >= $command->maxRuntimeSeconds) {
            return CriticalWorkerStopReason::RUNTIME_LIMIT;
        }

        return $this->runtime->memoryLimitReached() ? CriticalWorkerStopReason::MEMORY_LIMIT : null;
    }

    /** @param Closure(): void $operation */
    private function bounded(int $seconds, Closure $operation): void
    {
        $this->runtime->armDeadline($seconds);
        $failure = null;
        try {
            $operation();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        try {
            $this->runtime->disarmDeadline();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function cleanup(?CriticalWorkerException $failure): ?CriticalWorkerException
    {
        $cleanupFailed = false;
        try {
            $this->runtime->armDeadline($this->settings->shutdownTimeoutSeconds);
            foreach ([$this->receiver, $this->guard] as $resource) {
                try {
                    $resource->close();
                } catch (Throwable $exception) {
                    $cleanupFailed = true;
                    $failure ??= new CriticalWorkerException(
                        CriticalWorkerError::CLEANUP_FAILURE,
                        $exception instanceof BrokerTransportException ? null : $exception,
                        $resource === $this->receiver ? SafeCauseCode::TRANSPORT : SafeCauseCode::WORKER_RUNTIME,
                    );
                }
            }
        } catch (Throwable $exception) {
            $cleanupFailed = true;
            $failure ??= new CriticalWorkerException(CriticalWorkerError::CLEANUP_FAILURE, $exception, SafeCauseCode::WORKER_RUNTIME);
        } finally {
            try {
                $this->runtime->close();
            } catch (Throwable $exception) {
                $cleanupFailed = true;
                $failure ??= new CriticalWorkerException(CriticalWorkerError::CLEANUP_FAILURE, $exception, SafeCauseCode::WORKER_RUNTIME);
            }
        }

        if ($failure !== null) {
            $context = ['reason' => $failure->error->value, 'cause_code' => $failure->causeCode->value];
            if ($cleanupFailed) {
                $context['cleanup_failed'] = true;
            }
            try {
                $this->logger->error('critical_worker.stopped', $context);
            } catch (Throwable) {
                // Logging must not replace the original processing or cleanup failure.
            }
        }

        return $failure;
    }
}
