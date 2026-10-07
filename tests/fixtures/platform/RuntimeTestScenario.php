<?php

declare(strict_types=1);

namespace tests\fixtures\platform;

use modules\platform\application\command\RunCriticalWorkerCommand;
use modules\platform\application\dto\CriticalWorkerSettings;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\infrastructure\process\PcntlWorkerRuntime;

final class RuntimeTestScenario
{
    public static function run(string $scenario): int
    {
        $runtime = new PcntlWorkerRuntime();
        $settings = new CriticalWorkerSettings(1, 1, 1, 64, 32, 2);
        if ($scenario === 'expired-catchable') {
            $now = 10.0;
            $runtime = new PcntlWorkerRuntime(static function () use (&$now): float {
                return $now;
            });
            try {
                $runtime->start(new RunCriticalWorkerCommand(1, 2), $settings);
                $now = 13.0;
                $runtime->armDeadline(1);
            } catch (CriticalWorkerException $exception) {
                echo $exception->error->value . ' cause_code=' . $exception->causeCode->value;

                return 0;
            } finally {
                $runtime->close();
            }

            return 3;
        }
        if ($scenario === 'unstarted') {
            try {
                $runtime->armDeadline(1);
            } catch (CriticalWorkerException $exception) {
                echo $exception->getMessage();

                return 0;
            }

            return 3;
        }
        if ($scenario === 'capability') {
            try {
                $runtime->start(new RunCriticalWorkerCommand(1, 2), $settings);
            } catch (CriticalWorkerException $exception) {
                echo $exception->getMessage();

                return 0;
            }

            return 3;
        }
        if ($scenario === 'foreign-handler') {
            pcntl_async_signals(true);
            $fired = false;
            pcntl_signal(SIGALRM, static function () use (&$fired): void {
                $fired = true;
            });
            $previous = pcntl_signal_get_handler(SIGALRM);
            pcntl_alarm(1);
            try {
                $runtime->start(new RunCriticalWorkerCommand(1, 2), $settings);
            } catch (CriticalWorkerException $exception) {
                if (pcntl_signal_get_handler(SIGALRM) !== $previous) {
                    return 3;
                }
                while (!$fired) {
                    usleep(1000);
                }
                echo $exception->getMessage();

                return 0;
            }

            return 3;
        }
        if ($scenario === 'restore') {
            pcntl_async_signals(false);
            $previous = static function (): void {
            };
            pcntl_signal(SIGQUIT, $previous);
            $mask = [];
            pcntl_sigprocmask(SIG_BLOCK, [], $mask);
            $memory = ini_get('memory_limit');
            $runtime->start(new RunCriticalWorkerCommand(1, 2), $settings);
            $runtime->close();
            $restoredMask = [];
            pcntl_sigprocmask(SIG_BLOCK, [], $restoredMask);
            echo !pcntl_async_signals() && pcntl_signal_get_handler(SIGQUIT) === $previous
                && ini_get('memory_limit') === $memory && pcntl_alarm(0) === 0
                && $restoredMask === $mask ? 'RESTORED' : 'CHANGED';

            return 0;
        }
        if ($scenario === 'allocated-memory') {
            $allocation = str_repeat('x', 70 * 1024 * 1024);
            try {
                $runtime->start(new RunCriticalWorkerCommand(1, 2), $settings);
            } catch (CriticalWorkerException $exception) {
                echo $exception->getMessage();

                return strlen($allocation) > 0 ? 0 : 3;
            }

            return 3;
        }

        $runtime->start(new RunCriticalWorkerCommand(2, str_starts_with($scenario, 'signal') ? 10 : 2), $settings);
        if ($scenario === 'soft-memory') {
            $allocation = str_repeat('x', 34 * 1024 * 1024);
            echo $runtime->memoryLimitReached() && strlen($allocation) > 0 ? 'MEMORY_STOP' : 'MISSED';
            $runtime->close();

            return 0;
        }
        if ($scenario === 'hard-memory') {
            $allocation = str_repeat('x', 80 * 1024 * 1024);
            echo strlen($allocation) > 0 ? 'SUCCESS' : 'MISSED';

            return 0;
        }
        if ($scenario === 'overall') {
            $runtime->armDeadline(1);
            $runtime->disarmDeadline();
        } elseif ($scenario === 'native') {
            $runtime->armDeadline(1);
        } elseif (str_starts_with($scenario, 'signal')) {
            $runtime->armDeadline(2);
        }
        echo "READY\n";
        flush();
        if ($scenario === 'native') {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                return 4;
            }
            stream_set_timeout($pair[0], 60);
            fread($pair[0], 1);
            echo 'SUCCESS';

            return 0;
        }
        if (str_starts_with($scenario, 'signal')) {
            while (!$runtime->shouldStop()) {
                usleep(1000);
            }
            if ($scenario === 'signal-stop') {
                $runtime->disarmDeadline();
                echo 'STOPPED';
                $runtime->close();

                return 0;
            }
            echo "STOPPING\n";
            flush();
            $runtime->armDeadline(2);
            $runtime->disarmDeadline();
            $repeatAt = hrtime(true) / 1e9 + 1.0;
            while (hrtime(true) / 1e9 < $repeatAt) {
            }
            echo "REPEAT_READY\n";
            flush();
        }
        while (true) {
        }
    }
}
