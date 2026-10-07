<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\infrastructure\process\PcntlWorkerRuntime;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use tests\fixtures\platform\PlatformTestEnvironment;

final class CriticalWorkerRuntimeTest extends Unit
{
    public function testRuntimeConfigurationErrorHasSafeRuntimeCategory(): void
    {
        try {
            (new PcntlWorkerRuntime())->armDeadline(1);
            self::fail('Expected an unstarted runtime to reject a deadline.');
        } catch (CriticalWorkerException $exception) {
            self::assertSame('configuration_invalid', $exception->getMessage());
            self::assertSame(SafeCauseCode::WORKER_RUNTIME, $exception->causeCode);
        }
    }

    public function testCatchableDeadlineHasSafeRuntimeCategory(): void
    {
        $process = $this->process('expired-catchable');
        try {
            $process->run();
            self::assertSame(0, $process->getExitCode());
            self::assertSame('execution_deadline_exceeded cause_code=WORKER_RUNTIME', $process->getOutput());
            self::assertTrue($process->getErrorOutput() === '', 'unexpected_child_stderr');
        } finally {
            $process->stop(0.0, SIGKILL);
        }
    }

    /** @dataProvider hardDeadlineScenarios */
    public function testHardDeadlineInterruptsRealProcess(string $scenario): void
    {
        $process = $this->process($scenario);
        $started = hrtime(true) / 1e9;
        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "READY\n")));
            self::awaitExit($process);
            self::assertSame(SIGALRM, $process->getTermSignal());
            self::assertNotSame(0, $process->getExitCode());
            self::assertStringNotContainsString('SUCCESS', $process->getOutput());
            self::assertLessThan(3.5, hrtime(true) / 1e9 - $started);
        } finally {
            $process->stop(0.0, SIGKILL);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function hardDeadlineScenarios(): iterable
    {
        yield 'tight PHP loop' => ['tight'];
        yield 'blocking native read' => ['native'];
        yield 'disarm preserves overall deadline' => ['overall'];
    }

    /** @dataProvider stopSignals */
    public function testStopSignalAndRepeatedSignalPreserveShutdownBudget(int $signal): void
    {
        foreach (['signal-stop', 'signal-hang'] as $scenario) {
            $process = $this->process($scenario);
            try {
                $process->start();
                self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "READY\n")));
                $started = hrtime(true) / 1e9;
                $process->signal($signal);
                if ($scenario === 'signal-hang') {
                    self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "REPEAT_READY\n")));
                    $process->signal($signal);
                }
                self::awaitExit($process);
                if ($scenario === 'signal-stop') {
                    self::assertSame(0, $process->getExitCode());
                    self::assertStringContainsString('STOPPED', $process->getOutput());
                } else {
                    self::assertSame(SIGALRM, $process->getTermSignal());
                    self::assertLessThan(2.7, hrtime(true) / 1e9 - $started);
                }
                self::assertStringNotContainsString('SUCCESS', $process->getOutput());
            } finally {
                $process->stop(0.0, SIGKILL);
            }
        }
    }

    /** @return iterable<string, array{int}> */
    public static function stopSignals(): iterable
    {
        yield 'TERM' => [15];
        yield 'INT' => [2];
        yield 'QUIT' => [3];
    }

    /** @dataProvider normalScenarios */
    public function testStartupAndNormalClose(string $scenario, string $expected): void
    {
        $process = $this->process($scenario);
        try {
            $process->run();
            self::assertSame(0, $process->getExitCode());
            self::assertSame($expected, $process->getOutput());
        } finally {
            $process->stop(0.0, SIGKILL);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalScenarios(): iterable
    {
        yield 'settings restored' => ['restore', 'RESTORED'];
        yield 'unstarted runtime refuses blocking phase' => ['unstarted', 'configuration_invalid'];
        yield 'foreign handler refused unchanged' => ['foreign-handler', 'configuration_invalid'];
        yield 'soft memory stop' => ['soft-memory', 'MEMORY_STOP'];
        yield 'hard limit below allocated memory' => ['allocated-memory', 'configuration_invalid'];
    }

    public function testMissingCapabilityFailsSafely(): void
    {
        $process = $this->process('capability', ['-d', 'disable_functions=pcntl_alarm']);
        $process->run();
        self::assertSame(0, $process->getExitCode());
        self::assertSame('configuration_invalid', $process->getOutput());
    }

    public function testHardMemoryExhaustionIsNotSuccess(): void
    {
        $process = $this->process('hard-memory');
        $process->run();
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringNotContainsString('SUCCESS', $process->getOutput());
        self::assertTrue(str_contains($process->getErrorOutput(), 'Allowed memory size'), 'unexpected_child_stderr');
    }

    /** @param list<string> $options */
    private function process(string $scenario, array $options = []): Process
    {
        return new Process([
            PHP_BINARY, ...$options, 'tests/bin/critical-worker-runtime.php', $scenario,
        ], dirname(__DIR__, 5), PlatformTestEnvironment::databaseEnvironment(), null, 5.0);
    }

    private static function awaitExit(Process $process): void
    {
        try {
            $process->wait();
        } catch (ProcessSignaledException) {
            self::assertTrue($process->hasBeenSignaled());
        }
    }
}
