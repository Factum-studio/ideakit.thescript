<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\process;

use modules\platform\application\command\RunCriticalWorkerCommand;
use modules\platform\application\dto\CriticalWorkerSettings;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\port\IWorkerRuntime;

final class PcntlWorkerRuntime implements IWorkerRuntime
{
    private bool $active = false;
    private bool $stop = false;
    private bool $previousAsync = false;
    private ?string $previousMemoryLimit = null;
    /** @var array<int, callable|int> */
    private array $previousHandlers = [];
    private ?CriticalWorkerSettings $settings = null;
    private float $processDeadline = 0.0;
    private ?float $phaseDeadline = null;
    private ?float $shutdownDeadline = null;

    /** @throws CriticalWorkerException */
    public function start(RunCriticalWorkerCommand $command, CriticalWorkerSettings $settings): void
    {
        if ($this->active || PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID, causeCode: SafeCauseCode::WORKER_RUNTIME);
        }
        foreach (['pcntl_alarm', 'pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals', 'pcntl_sigprocmask'] as $function) {
            if (!function_exists($function)) {
                throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID, causeCode: SafeCauseCode::WORKER_RUNTIME);
            }
        }
        $mask = [];
        // The dedicated CLI process owns SIGALRM exclusively, including its timer.
        if (pcntl_signal_get_handler(SIGALRM) !== SIG_DFL
            || !pcntl_sigprocmask(SIG_BLOCK, [], $mask) || in_array(SIGALRM, $mask, true)
            || memory_get_usage(true) >= $settings->memoryLimitMib * 1024 * 1024
        ) {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID, causeCode: SafeCauseCode::WORKER_RUNTIME);
        }
        $previous = ini_set('memory_limit', $settings->memoryLimitMib . 'M');
        if ($previous === false || ini_get('memory_limit') !== $settings->memoryLimitMib . 'M') {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID, causeCode: SafeCauseCode::WORKER_RUNTIME);
        }
        $this->previousMemoryLimit = $previous;
        $this->previousAsync = pcntl_async_signals();
        foreach ([SIGALRM, SIGTERM, SIGINT, SIGQUIT] as $signal) {
            $this->previousHandlers[$signal] = pcntl_signal_get_handler($signal);
        }
        $this->settings = $settings;
        $this->stop = false;
        $this->phaseDeadline = $this->shutdownDeadline = null;
        $this->processDeadline = $this->monotonicSeconds() + $command->maxRuntimeSeconds;
        $this->active = true;
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, SIG_DFL);
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stop = true;
                if ($this->shutdownDeadline === null) {
                    $this->shutdownDeadline = $this->monotonicSeconds() + $this->settings->shutdownTimeoutSeconds;
                }
                $this->schedule();
            });
        }
        $this->schedule();
    }

    public function shouldStop(): bool
    {
        return $this->stop;
    }

    public function memoryLimitReached(): bool
    {
        return $this->settings !== null
            && memory_get_usage(true) >= $this->settings->softMemoryLimitMib * 1024 * 1024;
    }

    public function monotonicSeconds(): float
    {
        return hrtime(true) / 1e9;
    }

    /** @throws CriticalWorkerException */
    public function armDeadline(int $seconds): void
    {
        if (!$this->active) {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID, causeCode: SafeCauseCode::WORKER_RUNTIME);
        }
        if ($seconds < 1 || $seconds > $this->settings->shutdownTimeoutSeconds) {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID, causeCode: SafeCauseCode::WORKER_RUNTIME);
        }
        $this->assertTimeRemaining();
        $this->phaseDeadline = $this->monotonicSeconds() + $seconds;
        $this->schedule();
    }

    /** @throws CriticalWorkerException */
    public function disarmDeadline(): void
    {
        if (!$this->active) {
            return;
        }
        $this->assertTimeRemaining();
        $this->phaseDeadline = null;
        $this->schedule();
    }

    /** @throws CriticalWorkerException */
    public function close(): void
    {
        if (!$this->active) {
            return;
        }
        $mask = [];
        if (!pcntl_sigprocmask(SIG_BLOCK, array_keys($this->previousHandlers), $mask)) {
            throw new CriticalWorkerException(CriticalWorkerError::CLEANUP_FAILURE, causeCode: SafeCauseCode::WORKER_RUNTIME);
        }
        try {
            // A stop callback must not rearm the timer while its ownership is being released.
            pcntl_alarm(0);
            foreach ($this->previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($this->previousAsync);
            $this->active = false;
            $this->previousHandlers = [];
            $this->phaseDeadline = $this->shutdownDeadline = null;
            $this->settings = null;
            if ($this->previousMemoryLimit !== null) {
                $limit = $this->previousMemoryLimit;
                $this->previousMemoryLimit = null;
                if ($limit !== '-1' && memory_get_usage(true) > self::bytes($limit)) {
                    throw new CriticalWorkerException(CriticalWorkerError::CLEANUP_FAILURE, causeCode: SafeCauseCode::WORKER_RUNTIME);
                }
                if (ini_set('memory_limit', $limit) === false) {
                    throw new CriticalWorkerException(CriticalWorkerError::CLEANUP_FAILURE, causeCode: SafeCauseCode::WORKER_RUNTIME);
                }
            }
        } finally {
            if (!pcntl_sigprocmask(SIG_SETMASK, $mask)) {
                throw new CriticalWorkerException(CriticalWorkerError::CLEANUP_FAILURE, causeCode: SafeCauseCode::WORKER_RUNTIME);
            }
        }
    }

    private function deadline(): float
    {
        return min($this->processDeadline, $this->phaseDeadline ?? INF, $this->shutdownDeadline ?? INF);
    }

    private function schedule(): void
    {
        pcntl_alarm(max(1, (int) ceil($this->deadline() - $this->monotonicSeconds())));
    }

    /** @throws CriticalWorkerException */
    private function assertTimeRemaining(): void
    {
        if ($this->deadline() <= $this->monotonicSeconds()) {
            throw new CriticalWorkerException(CriticalWorkerError::TRANSPORT_FAILURE, causeCode: SafeCauseCode::WORKER_RUNTIME);
        }
    }

    private static function bytes(string $limit): int
    {
        $factor = match (strtoupper(substr($limit, -1))) {
            'G' => 1024 * 1024 * 1024, 'M' => 1024 * 1024, 'K' => 1024, default => 1,
        };

        return (int) $limit * $factor;
    }
}
