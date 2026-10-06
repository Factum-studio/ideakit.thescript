<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use Symfony\Component\Process\Process;
use tests\fixtures\platform\PlatformTestEnvironment;
use yii\helpers\FileHelper;

final class ConsoleErrorBoundaryTest extends Unit
{
    private string $runtime;

    protected function _before(): void
    {
        $this->runtime = sys_get_temp_dir() . '/ideakit-console-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->runtime, 0700));
    }

    protected function _after(): void
    {
        FileHelper::removeDirectory($this->runtime);
    }

    /** @dataProvider invalidConfigurations */
    public function testConfigurationFailureBeforeActionIsSafe(string $env, string $route, string $key, string $cause): void
    {
        $process = $this->process(['yii', $route], $env, false, 'root', [$key => '0']);
        $this->assertFailure($process, 'configuration_invalid cause_code=' . $cause);
        $isolated = $this->process([$route], $env, false, 'configuration', [$key => '0']);
        $this->assertFailure($isolated, 'configuration_invalid cause_code=' . $cause);
        $this->assertSafeLog('configuration_invalid cause_code=' . $cause);
    }

    /** @dataProvider unexpectedFailures */
    public function testUnexpectedFailureHasSafeProcessBoundary(string $env, bool $debug, string $scenario): void
    {
        $process = $this->process(['probe/fail'], $env, $debug, $scenario);
        $this->assertFailure($process, 'unexpected_failure cause_code=UNKNOWN');
        $this->assertSafeLog('unexpected_failure cause_code=UNKNOWN');
    }

    /** @dataProvider knownFailures */
    public function testTypedFailuresKeepClosedReasonAndCause(string $kind, string $expected): void
    {
        $process = $this->process(['probe/fail', $kind], 'test', true, 'action');
        $this->assertFailure($process, $expected);
        $this->assertSafeLog($expected);
    }

    /** @dataProvider environments */
    public function testDiagnosticFailureUsesSafeFallback(string $env, bool $debug): void
    {
        $process = $this->process(['probe/fail'], $env, $debug, 'log-failure');
        $this->assertFailure($process, 'unexpected_failure cause_code=UNKNOWN');
        $this->assertNoDisclosure($this->log());
    }

    public function testClosedErrorStreamStillFailsSafely(): void
    {
        $process = $this->process(['probe/fail', 'closed-stderr'], 'test', true, 'action');
        self::assertSame(1, $process->getExitCode());
        self::assertTrue($process->getOutput() === '', 'unexpected_child_stdout');
        self::assertTrue($process->getErrorOutput() === '', 'unexpected_child_stderr');
        $this->assertSafeLog('unexpected_failure cause_code=UNKNOWN');
    }

    public function testProbeWorksWithoutAutomaticEnvironmentPopulation(): void
    {
        $process = $this->process(['probe/fail', 'worker'], 'test', true, 'action', [], ['-d', 'variables_order=GPCS']);
        $this->assertFailure($process, 'handler_failure cause_code=HANDLER');
        $this->assertSafeLog('handler_failure cause_code=HANDLER');
    }

    public function testSilentExitOptionCannotHideUnhandledFailure(): void
    {
        $process = $this->process(['probe/fail', '--silentExitOnException=1'], 'test', false, 'action');
        $this->assertFailure($process, 'unexpected_failure cause_code=UNKNOWN');
        $this->assertSafeLog('unexpected_failure cause_code=UNKNOWN');
    }

    public function testSuccessfulHelpKeepsZeroExitCode(): void
    {
        $process = $this->process(['yii', 'help'], 'test', false, 'root');
        self::assertSame(0, $process->getExitCode());
        self::assertTrue(str_contains($process->getOutput(), 'platform-worker'), 'unexpected_child_stdout');
        self::assertTrue($process->getErrorOutput() === '', 'unexpected_child_stderr');
        self::assertSame('', $this->log());
    }

    public function testInvalidOptionKeepsExitTwoWithoutUnhandledEvent(): void
    {
        $process = $this->process(['yii', 'platform-worker/critical', '--limit=0'], 'test', false, 'root');
        self::assertSame(2, $process->getExitCode());
        self::assertTrue($process->getOutput() === '', 'unexpected_child_stdout');
        self::assertTrue($process->getErrorOutput() === "configuration_invalid\n", 'unexpected_child_stderr');
        self::assertSame('', $this->log());
    }

    public function testHandledMissingHandlerDoesNotLogTwice(): void
    {
        $process = $this->process(['platform-worker/critical'], 'test', false, 'configuration');
        $this->assertFailure($process, 'handler_missing cause_code=UNKNOWN');
        $log = $this->log();
        self::assertSame(1, substr_count($log, 'critical_worker.stopped'));
        self::assertFalse(str_contains($log, 'console.unhandled_failure'), 'unexpected_console_event');
        $this->assertNoDisclosure($log);
    }

    /** @dataProvider workerFailures */
    public function testWorkerFailureReachesSafeCliBoundary(string $scenario, string $reason, string $cause): void
    {
        $process = $this->process(['platform-worker/critical'], 'test', false, $scenario);
        $this->assertFailure($process, $reason . ' cause_code=' . $cause);
        $log = $this->log();
        self::assertSame(1, substr_count($log, 'critical_worker.stopped'));
        self::assertTrue(str_contains($log, $reason), 'missing_safe_reason');
        self::assertTrue(str_contains($log, $cause), 'missing_safe_cause');
        self::assertFalse(str_contains($log, 'console.unhandled_failure'), 'unexpected_console_event');
        $this->assertNoDisclosure($log);
        foreach (['previous', 'pgsql:', 'amqp://', 'synthetic-test-only', '#0'] as $marker) {
            self::assertFalse(str_contains($process->getErrorOutput() . $log, $marker), 'diagnostic_disclosure');
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function workerFailures(): iterable
    {
        yield 'unexpected runtime' => ['worker-runtime-failure', 'unexpected_failure', 'UNKNOWN'];
        yield 'incompatible topology' => ['worker-topology-failure', 'configuration_invalid', 'TRANSPORT'];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function environments(): iterable
    {
        foreach (['test', 'prod'] as $env) {
            foreach ([false, true] as $debug) {
                yield $env . '/' . ($debug ? 'debug' : 'no-debug') => [$env, $debug];
            }
        }
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function unexpectedFailures(): iterable
    {
        foreach (self::environments() as $name => [$env, $debug]) {
            foreach (['di', 'action'] as $scenario) {
                yield $name . '/' . $scenario => [$env, $debug, $scenario];
            }
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidConfigurations(): iterable
    {
        foreach (['test', 'prod'] as $env) {
            yield $env . '/worker' => [$env, 'platform-worker/critical', 'CRITICAL_WORKER_MAX_MESSAGES', 'UNKNOWN'];
            yield $env . '/relay' => [$env, 'platform-outbox/relay', 'OUTBOX_RELAY_MAX_ATTEMPTS', 'UNKNOWN'];
            yield $env . '/broker' => [$env, 'platform-messaging/declare', 'RABBITMQ_PORT', 'TRANSPORT'];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function knownFailures(): iterable
    {
        yield 'worker' => ['worker', 'handler_failure cause_code=HANDLER'];
        yield 'relay' => ['relay', 'persistence_failure cause_code=PERSISTENCE'];
        yield 'maintenance' => ['maintenance', 'persistence_failure cause_code=PERSISTENCE'];
        yield 'broker' => ['broker', 'connection_failure cause_code=TRANSPORT'];
        yield 'writer' => ['writer', 'persistence_failure cause_code=PERSISTENCE'];
        yield 'conflict' => ['writer-conflict', 'idempotency_conflict cause_code=UNKNOWN'];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $overrides
     * @param list<string> $options
     */
    private function process(array $arguments, string $env, bool $debug, string $scenario, array $overrides = [], array $options = []): Process
    {
        $command = $scenario === 'root'
            ? [PHP_BINARY, ...$arguments]
            : [PHP_BINARY, ...$options, 'tests/bin/platform-console-error-probe.php', ...$arguments];
        $process = new Process($command, dirname(__DIR__, 3), array_merge(PlatformTestEnvironment::applicationEnvironment(), [
            'APP_ENV' => $env, 'APP_DEBUG' => $debug ? 'true' : 'false',
            'CONSOLE_TEST_RUNTIME' => $this->runtime, 'CONSOLE_TEST_SCENARIO' => $scenario,
        ], $overrides), null, 10.0);
        $process->run();

        return $process;
    }

    private function assertFailure(Process $process, string $expected): void
    {
        self::assertSame(1, $process->getExitCode());
        self::assertTrue($process->getOutput() === '', 'unexpected_child_stdout');
        self::assertTrue($process->getErrorOutput() === $expected . "\n", 'unexpected_child_stderr');
        $this->assertNoDisclosure($process->getOutput() . $process->getErrorOutput());
    }

    private function assertSafeLog(string $expected): void
    {
        self::assertFileExists($this->runtime . '/console.log');
        $log = $this->log();
        self::assertTrue(str_contains($log, $expected), 'missing_safe_diagnostic');
        self::assertSame(1, substr_count($log, 'console.unhandled_failure'));
        $this->assertNoDisclosure($log);
    }

    private function log(): string
    {
        $file = $this->runtime . '/console.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    private function assertNoDisclosure(string $output): void
    {
        foreach (['synthetic-sensitive-', 'RuntimeException', 'Stack trace', 'Caused by', 'SELECT ', 'PDOException'] as $marker) {
            self::assertFalse(str_contains($output, $marker), 'diagnostic_disclosure');
        }
    }
}
