<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Closure;
use Codeception\Test\Unit;
use modules\platform\application\command\RunCriticalWorkerCommand;
use modules\platform\application\dto\BackgroundCommandRegistration;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\CriticalWorkerSettings;
use modules\platform\application\enum\BackgroundCommandOutcome;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\enum\CriticalWorkerStopReason;
use modules\platform\application\exception\BackgroundCommandRejectedException;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\handler\RunCriticalWorkerHandler;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\port\IBackgroundCommandHandler;
use modules\platform\application\port\IBrokerDelivery;
use modules\platform\application\port\IBrokerReceiver;
use modules\platform\application\port\IWorkerExecutionGuard;
use modules\platform\application\port\IWorkerRuntime;
use modules\platform\application\route\BackgroundCommandRegistry;
use tests\fixtures\platform\TestOutboxRoutes;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class RunCriticalWorkerHandlerTest extends Unit
{
    private IBrokerReceiver&MockObject $receiver;
    private IWorkerRuntime&MockObject $runtime;
    private IWorkerExecutionGuard&MockObject $guard;
    private IBackgroundCommandHandler&MockObject $background;
    private LoggerInterface&MockObject $logger;
    /** @var list<string> */
    private array $events = [];
    /** @var list<int> */
    private array $deadlines = [];
    private int $phase = 0;
    private int $guardCalls = 0;
    private int $dirtyAt = 0;
    private float $now = 10.0;
    private bool $signal = false;
    private bool $memoryLimit = false;

    protected function _before(): void
    {
        $this->receiver = $this->createMock(IBrokerReceiver::class);
        $this->runtime = $this->createMock(IWorkerRuntime::class);
        $this->guard = $this->createMock(IWorkerExecutionGuard::class);
        $this->background = $this->createMock(IBackgroundCommandHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->runtime->method('start')->willReturnCallback(function (): void {
            $this->events[] = 'runtime_start';
        });
        $this->runtime->method('shouldStop')->willReturnCallback(fn (): bool => $this->signal);
        $this->runtime->method('memoryLimitReached')->willReturnCallback(fn (): bool => $this->memoryLimit);
        $this->runtime->method('monotonicSeconds')->willReturnCallback(fn (): float => $this->now);
        $this->runtime->method('armDeadline')->willReturnCallback(function (int $seconds): void {
            $this->deadlines[] = $seconds;
            $this->phase = $seconds;
        });
        $this->runtime->method('disarmDeadline')->willReturnCallback(function (): void {
            $this->phase = 0;
        });
        $this->runtime->method('close')->willReturnCallback(function (): void {
            $this->events[] = 'runtime_close';
        });
        $this->guard->method('assertClean')->willReturnCallback(function (): void {
            $this->events[] = 'guard';
            if (++$this->guardCalls === $this->dirtyAt) {
                throw new CriticalWorkerException(CriticalWorkerError::EXECUTION_SCOPE_DIRTY);
            }
        });
        $this->guard->method('close')->willReturnCallback(function (): void {
            $this->events[] = 'guard_close';
        });
        $this->receiver->method('close')->willReturnCallback(function (): void {
            $this->events[] = 'receiver_close';
            self::assertSame(10, $this->phase);
        });
    }

    /** @dataProvider outcomes */
    public function testAcknowledgesOnlyAfterHandlerAndCleanScope(BackgroundCommandOutcome $outcome): void
    {
        $message = self::message();
        $delivery = $this->delivery($message);
        $this->receive($delivery);
        $this->background->expects(self::once())->method('handle')->with(self::identicalTo($message))
            ->willReturnCallback(function () use ($outcome): BackgroundCommandOutcome {
                self::assertSame(4, $this->phase);
                $this->events[] = 'handler_commit';

                return $outcome;
            });
        $delivery->expects(self::once())->method('acknowledge')->willReturnCallback(function (): void {
            self::assertSame(10, $this->phase);
            $this->events[] = 'ack';
        });
        $delivery->expects(self::never())->method('reject');

        $receipt = $this->worker()->handle(new RunCriticalWorkerCommand(1, 30));

        self::assertSame(
            [1, (int) ($outcome === BackgroundCommandOutcome::COMPLETED),
            (int) ($outcome === BackgroundCommandOutcome::ALREADY_COMPLETED), 0],
            [$receipt->received, $receipt->completed, $receipt->alreadyCompleted, $receipt->rejected],
        );
        self::assertSame(CriticalWorkerStopReason::MESSAGE_LIMIT, $receipt->stopReason);
        self::assertSame(['guard', 'runtime_start', 'guard', 'receive', 'message', 'handler_commit',
            'guard', 'ack', 'receiver_close', 'guard_close', 'runtime_close'], $this->events);
        self::assertSame([10, 10, 4, 10, 10], $this->deadlines);
    }

    /** @return iterable<string, array{BackgroundCommandOutcome}> */
    public static function outcomes(): iterable
    {
        yield 'completed' => [BackgroundCommandOutcome::COMPLETED];
        yield 'already completed' => [BackgroundCommandOutcome::ALREADY_COMPLETED];
    }

    /** @dataProvider terminalRefusals */
    public function testRejectsOnlyExplicitTerminalReasons(string $scenario, string $reason): void
    {
        $message = match ($scenario) {
            'malformed' => new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE),
            'unsupported decode' => new BrokerTransportException(BrokerTransportErrorCode::UNSUPPORTED_CONTRACT),
            'unknown version' => self::message('2.0'),
            'unknown type' => self::message('1.0', 'future.command'),
            default => self::message(),
        };
        $delivery = $this->delivery($message);
        $this->receive($delivery);
        if ($reason === 'handler_rejected') {
            $this->background->expects(self::once())->method('handle')
                ->willThrowException(new BackgroundCommandRejectedException());
        } else {
            $this->background->expects(self::never())->method('handle');
        }
        $delivery->expects(self::never())->method('acknowledge');
        $delivery->expects(self::once())->method('reject')->willReturnCallback(function (): void {
            self::assertSame(10, $this->phase);
            self::assertSame('guard', $this->events[array_key_last($this->events)]);
        });
        $this->logger->expects(self::once())->method('warning')
            ->with('critical_worker.rejected', ['reason' => $reason])->willReturnCallback(function (): void {
                self::assertSame(10, $this->phase);
            });

        $receipt = $this->worker()->handle(new RunCriticalWorkerCommand(1, 30));

        self::assertSame(
            [1, 0, 0, 1],
            [$receipt->received, $receipt->completed, $receipt->alreadyCompleted, $receipt->rejected],
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function terminalRefusals(): iterable
    {
        yield 'malformed' => ['malformed', 'invalid_envelope'];
        yield 'unsupported decode' => ['unsupported decode', 'unsupported_contract'];
        yield 'unknown version' => ['unknown version', 'unsupported_contract'];
        yield 'unknown type' => ['unknown type', 'unsupported_contract'];
        yield 'terminal refusal' => ['terminal refusal', 'handler_rejected'];
    }

    /** @dataProvider handlerFailures */
    public function testHandlerFailureDoesNotBecomeTerminalRejection(Throwable $failure): void
    {
        $delivery = $this->delivery();
        $this->receive($delivery);
        $this->background->expects(self::once())->method('handle')->willThrowException($failure);
        $delivery->expects(self::never())->method('acknowledge');
        $delivery->expects(self::never())->method('reject');

        $this->assertFailure(CriticalWorkerError::HANDLER_FAILURE);
        $this->assertCleanup();
    }

    /** @return iterable<string, array{Throwable}> */
    public static function handlerFailures(): iterable
    {
        yield 'database failure' => [new RuntimeException('synthetic-private-detail')];
        yield 'unexpected error' => [new \TypeError('synthetic-private-detail')];
        yield 'transport-shaped handler failure' => [
            new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE),
        ];
    }

    /** @dataProvider settlementFailures */
    public function testAmbiguousSettlementStopsWithoutRepeatingHandler(bool $reject): void
    {
        $delivery = $this->delivery();
        $this->receive($delivery);
        if ($reject) {
            $this->background->expects(self::once())->method('handle')
                ->willThrowException(new BackgroundCommandRejectedException());
        } else {
            $this->background->expects(self::once())->method('handle')->willReturn(BackgroundCommandOutcome::COMPLETED);
        }
        $delivery->expects(self::once())->method($reject ? 'reject' : 'acknowledge')
            ->willThrowException(new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE));
        $delivery->expects(self::never())->method($reject ? 'acknowledge' : 'reject');

        $this->assertFailure(CriticalWorkerError::TRANSPORT_FAILURE);
        $this->assertCleanup();
    }

    /** @return iterable<string, array{bool}> */
    public static function settlementFailures(): iterable
    {
        yield 'ack' => [false];
        yield 'reject' => [true];
    }

    /** @dataProvider transportFailures */
    public function testTransportFailureIsNotMisclassifiedAsMalformed(string $operation, BrokerTransportErrorCode $code): void
    {
        $delivery = $this->delivery(new BrokerTransportException($code));
        if ($operation === 'receive') {
            $this->receiver->expects(self::once())->method('receive')
                ->willThrowException(new BrokerTransportException($code));
            $delivery->expects(self::never())->method('message');
        } else {
            $this->receive($delivery);
        }
        $this->background->expects(self::never())->method('handle');
        $delivery->expects(self::never())->method('acknowledge');
        $delivery->expects(self::never())->method('reject');

        $this->assertFailure(CriticalWorkerError::TRANSPORT_FAILURE);
    }

    /** @return iterable<string, array{string, BrokerTransportErrorCode}> */
    public static function transportFailures(): iterable
    {
        yield 'receive failure' => ['receive', BrokerTransportErrorCode::CONNECTION_FAILURE];
        yield 'invalid envelope outside decode' => ['receive', BrokerTransportErrorCode::INVALID_ENVELOPE];
        yield 'decode connection failure' => ['decode', BrokerTransportErrorCode::CONNECTION_FAILURE];
    }

    public function testMissingCriticalRegistrationRefusesReceive(): void
    {
        $this->receiver->expects(self::never())->method('receive');
        $this->runtime->expects(self::never())->method('start');

        $this->assertFailure(CriticalWorkerError::HANDLER_MISSING, new BackgroundCommandRegistry([], TestOutboxRoutes::registry()));
        $this->assertCleanup();
    }

    /** @dataProvider dirtyScopes */
    public function testDirtyScopeForbidsConsumeOrSettlement(int $dirtyAt, bool $terminal): void
    {
        $this->dirtyAt = $dirtyAt;
        $delivery = $this->delivery();
        if ($dirtyAt <= 2) {
            $this->receiver->expects(self::never())->method('receive');
            $this->background->expects(self::never())->method('handle');
        } else {
            $this->receive($delivery);
            if ($terminal) {
                $this->background->expects(self::once())->method('handle')
                    ->willThrowException(new BackgroundCommandRejectedException());
            } else {
                $this->background->expects(self::once())->method('handle')
                    ->willReturn(BackgroundCommandOutcome::COMPLETED);
            }
        }
        $delivery->expects(self::never())->method('acknowledge');
        $delivery->expects(self::never())->method('reject');

        $this->assertFailure(CriticalWorkerError::EXECUTION_SCOPE_DIRTY);
        $this->assertCleanup();
    }

    /** @return iterable<string, array{int, bool}> */
    public static function dirtyScopes(): iterable
    {
        yield 'startup' => [1, false];
        yield 'before receive' => [2, false];
        yield 'completed handler' => [3, false];
        yield 'terminal handler' => [3, true];
    }

    public function testIdleReceiveContinuesUntilLifetimeLimit(): void
    {
        $this->receiver->expects(self::exactly(2))->method('receive')->with(1.0)->willReturnCallback(function () {
            $this->now += 1.0;

            return null;
        });
        $this->background->expects(self::never())->method('handle');

        $receipt = $this->worker()->handle(new RunCriticalWorkerCommand(2, 2));

        self::assertSame(0, $receipt->received);
        self::assertSame(CriticalWorkerStopReason::RUNTIME_LIMIT, $receipt->stopReason);
    }

    public function testSoftMemoryLimitStopsBeforeReceive(): void
    {
        $this->memoryLimit = true;
        $this->receiver->expects(self::never())->method('receive');

        $receipt = $this->worker()->handle(new RunCriticalWorkerCommand(1, 30));

        self::assertSame(CriticalWorkerStopReason::MEMORY_LIMIT, $receipt->stopReason);
    }

    /** @dataProvider signalPhases */
    public function testSignalBeforeHandlerLeavesCurrentDeliveryUnsettled(bool $duringDecode): void
    {
        $requestStop = function (): void {
            $this->signal = true;
        };
        $delivery = $this->delivery(self::message(), $duringDecode ? $requestStop : null);
        $this->receive($delivery, $duringDecode ? null : $requestStop);
        if (!$duringDecode) {
            $delivery->expects(self::never())->method('message');
        }
        $this->background->expects(self::never())->method('handle');
        $delivery->expects(self::never())->method('acknowledge');
        $delivery->expects(self::never())->method('reject');

        $receipt = $this->worker()->handle(new RunCriticalWorkerCommand(2, 30));

        self::assertSame(
            [1, 0, 0, 0],
            [$receipt->received, $receipt->completed, $receipt->alreadyCompleted, $receipt->rejected],
        );
        self::assertSame(CriticalWorkerStopReason::SIGNAL, $receipt->stopReason);
    }

    /** @return iterable<string, array{bool}> */
    public static function signalPhases(): iterable
    {
        yield 'during receive' => [false];
        yield 'during decode' => [true];
    }

    public function testSignalDuringHandlerAllowsOnlyCurrentSavedEffectToBeAcknowledged(): void
    {
        $delivery = $this->delivery();
        $this->receive($delivery);
        $this->background->expects(self::once())->method('handle')->willReturnCallback(function (): BackgroundCommandOutcome {
            $this->signal = true;

            return BackgroundCommandOutcome::COMPLETED;
        });
        $delivery->expects(self::once())->method('acknowledge');
        $delivery->expects(self::never())->method('reject');

        $receipt = $this->worker()->handle(new RunCriticalWorkerCommand(2, 30));

        self::assertSame(1, $receipt->completed);
        self::assertSame(CriticalWorkerStopReason::SIGNAL, $receipt->stopReason);
    }

    /** @dataProvider cleanupFailures */
    public function testCleanupFailureNeverMasksPrimaryFailureOrReturnsSuccess(bool $primaryFailure): void
    {
        $delivery = $this->delivery();
        $this->receive($delivery);
        if ($primaryFailure) {
            $this->background->expects(self::once())->method('handle')->willThrowException(new RuntimeException('synthetic'));
        } else {
            $this->background->expects(self::once())->method('handle')->willReturn(BackgroundCommandOutcome::COMPLETED);
            $delivery->expects(self::once())->method('acknowledge');
        }
        $this->guard = $this->createMock(IWorkerExecutionGuard::class);
        $this->guard->expects(self::once())->method('close')->willThrowException(new RuntimeException('synthetic'));
        $this->runtime->expects(self::once())->method('close');
        $expected = $primaryFailure ? CriticalWorkerError::HANDLER_FAILURE : CriticalWorkerError::CLEANUP_FAILURE;
        $this->logger->expects(self::once())->method('error')
            ->with('critical_worker.stopped', ['reason' => $expected->value, 'cleanup_failed' => true])
            ->willReturnCallback(function (): void {
                self::assertSame(10, $this->phase);
            });

        $this->assertFailure($expected);
    }

    /** @return iterable<string, array{bool}> */
    public static function cleanupFailures(): iterable
    {
        yield 'primary failure preserved' => [true];
        yield 'successful effect is not a successful run' => [false];
    }

    /** @dataProvider invalidLimits */
    public function testRejectsInvalidCommandAndSettingsLimits(string $field, int $value): void
    {
        $this->expectException(CriticalWorkerException::class);
        $this->expectExceptionMessage('configuration_invalid');
        if ($field === 'maxMessages' || $field === 'maxRuntimeSeconds') {
            new RunCriticalWorkerCommand($field === 'maxMessages' ? $value : 1, $field === 'maxRuntimeSeconds' ? $value : 30);

            return;
        }
        $values = [4, 10, 1, 256, 192, 10];
        $fields = ['handlerTimeoutSeconds', 'brokerOperationTimeoutSeconds', 'receiveTimeoutSeconds',
            'memoryLimitMib', 'softMemoryLimitMib', 'shutdownTimeoutSeconds'];
        $position = array_search($field, $fields, true);
        self::assertIsInt($position);
        $values[$position] = $value;
        new CriticalWorkerSettings(...$values);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidLimits(): iterable
    {
        yield 'message minimum' => ['maxMessages', 0];
        yield 'message maximum' => ['maxMessages', 10001];
        yield 'lifetime minimum' => ['maxRuntimeSeconds', 0];
        yield 'lifetime maximum' => ['maxRuntimeSeconds', 3601];
        yield 'handler minimum' => ['handlerTimeoutSeconds', 0];
        yield 'handler maximum' => ['handlerTimeoutSeconds', 31];
        yield 'handler exceeds shutdown' => ['handlerTimeoutSeconds', 11];
        yield 'broker minimum' => ['brokerOperationTimeoutSeconds', 0];
        yield 'broker maximum' => ['brokerOperationTimeoutSeconds', 21];
        yield 'broker exceeds shutdown' => ['brokerOperationTimeoutSeconds', 11];
        yield 'receive minimum' => ['receiveTimeoutSeconds', 0];
        yield 'receive maximum' => ['receiveTimeoutSeconds', 6];
        yield 'memory minimum' => ['memoryLimitMib', 63];
        yield 'memory maximum' => ['memoryLimitMib', 513];
        yield 'soft minimum' => ['softMemoryLimitMib', 31];
        yield 'soft reaches hard limit' => ['softMemoryLimitMib', 256];
        yield 'shutdown minimum' => ['shutdownTimeoutSeconds', 0];
        yield 'shutdown maximum' => ['shutdownTimeoutSeconds', 21];
        yield 'shutdown below broker' => ['shutdownTimeoutSeconds', 9];
    }

    private function worker(?BackgroundCommandRegistry $registry = null): RunCriticalWorkerHandler
    {
        return new RunCriticalWorkerHandler(
            $this->receiver,
            $registry ?? new BackgroundCommandRegistry([
                new BackgroundCommandRegistration('telegram.update.received', '1.0', $this->background),
            ], TestOutboxRoutes::registry()),
            $this->runtime,
            $this->guard,
            new CriticalWorkerSettings(4, 10, 1, 256, 192, 10),
            $this->logger,
        );
    }

    private function assertFailure(CriticalWorkerError $expected, ?BackgroundCommandRegistry $registry = null): void
    {
        try {
            $this->worker($registry)->handle(new RunCriticalWorkerCommand(1, 30));
            self::fail('Worker unexpectedly returned a successful receipt.');
        } catch (CriticalWorkerException $exception) {
            self::assertSame($expected, $exception->error);
            self::assertSame($expected->value, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private function assertCleanup(): void
    {
        self::assertSame(['receiver_close', 'guard_close', 'runtime_close'], array_slice($this->events, -3));
    }

    /** @param ?Closure(): void $effect */
    private function receive(IBrokerDelivery $delivery, ?Closure $effect = null): void
    {
        $this->receiver->expects(self::once())->method('receive')->with(1.0)->willReturnCallback(
            function () use ($delivery, $effect): IBrokerDelivery {
                $this->events[] = 'receive';
                self::assertSame(10, $this->phase);
                $effect?->__invoke();

                return $delivery;
            },
        );
    }

    /** @param ?Closure(): void $effect */
    private function delivery(BrokerEnvelope|Throwable|null $message = null, ?Closure $effect = null): IBrokerDelivery&MockObject
    {
        $delivery = $this->createMock(IBrokerDelivery::class);
        $delivery->method('message')->willReturnCallback(function () use ($message, $effect): BrokerEnvelope {
            $this->events[] = 'message';
            self::assertSame(10, $this->phase);
            $effect?->__invoke();
            if ($message instanceof Throwable) {
                throw $message;
            }

            return $message ?? self::message();
        });

        return $delivery;
    }

    private static function message(string $version = '1.0', string $type = 'telegram.update.received'): BrokerEnvelope
    {
        return new BrokerEnvelope(
            '01890f4d-3c2a-7f48-8c0b-123456789ac4',
            $type,
            $version,
            '01890f4d-3c2a-7f48-8c0b-123456789ac5',
            new TelegramUpdateReceivedPayload('01890f4d-3c2a-7f48-8c0b-123456789ac6'),
        );
    }
}
