<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\application\handler;

use Codeception\Test\Unit;
use InvalidArgumentException;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\dto\OutboxWriteReceipt;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\port\IOutboxWriter;
use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\dto\TelegramInboxReservation;
use modules\telegram\application\enum\IncomingTelegramUpdateType;
use modules\telegram\application\enum\TelegramInboxReservationOutcome;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use modules\telegram\application\enum\TelegramUpdateIgnoreReason;
use modules\telegram\application\exception\InvalidTelegramUpdatePayloadException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceIntegrityException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;
use modules\telegram\application\handler\AcceptTelegramUpdateHandler;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\telegram\application\port\ITelegramAcceptanceIdGenerator;
use modules\telegram\application\port\ITelegramInboxStore;
use modules\telegram\application\port\ITelegramInboxTransactionRunner;
use RuntimeException;
use Throwable;

final class AcceptTelegramUpdateHandlerTest extends Unit
{
    private const INBOX_ID = '01960000-0000-7000-8000-000000000001';
    private const CORRELATION_ID = '01960000-0000-7000-8000-000000000002';
    private const OUTBOX_ID = '01960000-0000-7000-8000-000000000003';

    /** @dataProvider incomingCommands */
    public function testNewUpdateWritesOnlyInboxReferenceInsideTransaction(bool $ignored): void
    {
        $command = self::command($ignored);
        $reservation = new TelegramInboxReservation(self::INBOX_ID, TelegramInboxReservationOutcome::CREATED);
        $events = [];
        $store = $this->createMock(ITelegramInboxStore::class);
        $store->expects(self::once())->method('reserve')->with(self::identicalTo($command))
            ->willReturnCallback(static function () use (&$events, $reservation): TelegramInboxReservation {
                $events[] = 'reserve';
                return $reservation;
            });
        $writer = $this->createMock(IOutboxWriter::class);
        $writer->expects(self::once())->method('write')
            ->willReturnCallback(static function (OutboxWriteIntent $intent) use (&$events): OutboxWriteReceipt {
                $events[] = 'write';
                self::assertSame('Telegram', $intent->ownerModule);
                self::assertSame('telegram.update.received', $intent->messageType);
                self::assertSame('1.0', $intent->schemaVersion);
                self::assertSame('TELEGRAM_UPDATE', $intent->aggregateType);
                self::assertSame(self::INBOX_ID, $intent->aggregateId);
                self::assertSame('telegram.update.received/1.0/' . self::INBOX_ID, $intent->idempotencyKey);
                self::assertSame(self::CORRELATION_ID, $intent->correlationId);
                self::assertInstanceOf(TelegramUpdateReceivedPayload::class, $intent->payload);
                self::assertSame(['update_id' => self::INBOX_ID], $intent->payload->technicalFields());
                return new OutboxWriteReceipt(self::OUTBOX_ID, OutboxWriteOutcome::CREATED);
            });
        $runner = $this->createMock(ITelegramInboxTransactionRunner::class);
        $runner->expects(self::once())->method('run')->willReturnCallback(
            static function (callable $operation) use (&$events, $reservation): TelegramInboxReservation {
                $events[] = 'begin';
                $result = $operation();
                self::assertSame($reservation, $result);
                $events[] = 'commit';
                return $result;
            },
        );

        $receipt = (new AcceptTelegramUpdateHandler($store, $runner, $writer, $this->generator()))->handle($command);

        $events[] = 'receipt';
        self::assertSame(['begin', 'reserve', 'write', 'commit', 'receipt'], $events);
        self::assertSame(self::INBOX_ID, $receipt->inboxId);
        self::assertSame(TelegramUpdateAcceptanceOutcome::ACCEPTED, $receipt->outcome);
    }

    /** @return iterable<string, array{bool}> */
    public static function incomingCommands(): iterable
    {
        yield 'message' => [false];
        yield 'ignored' => [true];
    }

    public function testExistingUpdateDoesNotWriteOrGenerateCorrelationId(): void
    {
        $store = $this->createMock(ITelegramInboxStore::class);
        $store->expects(self::once())->method('reserve')->willReturn(
            new TelegramInboxReservation(self::INBOX_ID, TelegramInboxReservationOutcome::EXISTING),
        );
        $writer = $this->createMock(IOutboxWriter::class);
        $writer->expects(self::never())->method('write');
        $generator = $this->createMock(ITelegramAcceptanceIdGenerator::class);
        $generator->expects(self::never())->method('generate');

        $receipt = (new AcceptTelegramUpdateHandler($store, $this->runner(), $writer, $generator))->handle(self::command());

        self::assertSame(self::INBOX_ID, $receipt->inboxId);
        self::assertSame(TelegramUpdateAcceptanceOutcome::DUPLICATE, $receipt->outcome);
    }

    public function testRunnerFailureAfterSuccessfulCallbackCannotReturnReceipt(): void
    {
        $failure = new TelegramUpdateAcceptanceUnavailableException(new RuntimeException('synthetic-commit-failure'));
        $runner = $this->createMock(ITelegramInboxTransactionRunner::class);
        $runner->expects(self::once())->method('run')->willReturnCallback(
            static function (callable $operation) use ($failure): TelegramInboxReservation {
                $operation();
                throw $failure;
            },
        );
        $writer = $this->createMock(IOutboxWriter::class);
        $writer->expects(self::once())->method('write')->willReturn(
            new OutboxWriteReceipt(self::OUTBOX_ID, OutboxWriteOutcome::CREATED),
        );

        $this->expectExceptionObject($failure);
        (new AcceptTelegramUpdateHandler($this->newInbox(), $runner, $writer, $this->generator()))->handle(self::command());
    }

    public function testExistingOutboxForNewInboxIsIntegrityFailure(): void
    {
        $writer = $this->createMock(IOutboxWriter::class);
        $writer->expects(self::once())->method('write')->willReturn(
            new OutboxWriteReceipt(self::OUTBOX_ID, OutboxWriteOutcome::ALREADY_EXISTS),
        );

        $this->expectException(TelegramUpdateAcceptanceIntegrityException::class);
        $this->expectExceptionMessage('telegram_update_acceptance_integrity_failure');
        (new AcceptTelegramUpdateHandler($this->newInbox(), $this->runner(), $writer, $this->generator()))->handle(self::command());
    }

    /** @dataProvider writerFailures */
    public function testWriterFailureReachesRunnerWithOriginalCause(OutboxWriteFailure $code): void
    {
        $cause = new RuntimeException('synthetic-private-driver-detail');
        $failure = new OutboxWriteException($code, $cause);
        $writer = $this->createMock(IOutboxWriter::class);
        $writer->expects(self::once())->method('write')->willThrowException($failure);
        $runner = $this->createMock(ITelegramInboxTransactionRunner::class);
        $runner->expects(self::once())->method('run')->willReturnCallback(
            static function (callable $operation) use ($code, $failure, $cause): TelegramInboxReservation {
                try {
                    $operation();
                    self::fail('Writer failure must leave the transaction callback.');
                } catch (OutboxWriteException | TelegramUpdateAcceptanceIntegrityException $exception) {
                    if ($code === OutboxWriteFailure::PERSISTENCE_FAILURE) {
                        self::assertSame($failure, $exception);
                        self::assertSame($cause, $exception->getPrevious());
                    } else {
                        self::assertInstanceOf(TelegramUpdateAcceptanceIntegrityException::class, $exception);
                        self::assertSame('telegram_update_acceptance_integrity_failure', $exception->getMessage());
                        self::assertSame($failure, $exception->getPrevious());
                    }
                    throw $exception;
                }
            },
        );

        $this->expectException($code === OutboxWriteFailure::PERSISTENCE_FAILURE
            ? OutboxWriteException::class : TelegramUpdateAcceptanceIntegrityException::class);
        (new AcceptTelegramUpdateHandler($this->newInbox(), $runner, $writer, $this->generator()))->handle(self::command());
    }

    /** @return iterable<string, array{OutboxWriteFailure}> */
    public static function writerFailures(): iterable
    {
        foreach (OutboxWriteFailure::cases() as $failure) {
            yield $failure->value => [$failure];
        }
    }

    /** @dataProvider reservationFailures */
    public function testReservationFailureIsNotRewrappedOrRetried(string $kind): void
    {
        $cause = new RuntimeException('synthetic-internal-cause');
        $failure = match ($kind) {
            'input' => new InvalidTelegramUpdatePayloadException($cause),
            'integrity' => new TelegramUpdateAcceptanceIntegrityException($cause),
            'unavailable' => new TelegramUpdateAcceptanceUnavailableException($cause),
            default => $cause,
        };
        $store = $this->createMock(ITelegramInboxStore::class);
        $store->expects(self::once())->method('reserve')->willThrowException($failure);
        $writer = $this->createMock(IOutboxWriter::class);
        $writer->expects(self::never())->method('write');
        $generator = $this->createMock(ITelegramAcceptanceIdGenerator::class);
        $generator->expects(self::never())->method('generate');

        try {
            (new AcceptTelegramUpdateHandler($store, $this->runner(), $writer, $generator))->handle(self::command());
            self::fail('Reservation failure must prevent acceptance.');
        } catch (Throwable $exception) {
            self::assertSame($failure, $exception);
        }
    }

    /** @return iterable<array{string}> */
    public static function reservationFailures(): iterable
    {
        yield ['input'];
        yield ['integrity'];
        yield ['unavailable'];
        yield ['unexpected'];
    }

    public function testInvalidGeneratedCorrelationIdIsSafeIntegrityFailureBeforeWriter(): void
    {
        $writer = $this->createMock(IOutboxWriter::class);
        $writer->expects(self::never())->method('write');

        try {
            (new AcceptTelegramUpdateHandler($this->newInbox(), $this->runner(), $writer, $this->generator('invalid')))
                ->handle(self::command());
            self::fail('Invalid intent must prevent acceptance.');
        } catch (TelegramUpdateAcceptanceIntegrityException $exception) {
            self::assertSame('telegram_update_acceptance_integrity_failure', $exception->getMessage());
            self::assertInstanceOf(OutboxWriteException::class, $exception->getPrevious());
        }
    }

    /** @dataProvider invalidInboxIds */
    public function testReservationRejectsNonCanonicalInboxIds(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_telegram_inbox_reservation');
        new TelegramInboxReservation($id, TelegramInboxReservationOutcome::CREATED);
    }

    /** @return iterable<array{string}> */
    public static function invalidInboxIds(): iterable
    {
        yield ['123'];
        yield ['0196AAAA-0000-7000-8000-000000000001'];
        yield ['01960000000070008000000000000001'];
    }

    public function testTypedFailuresKeepPrivateCauseAndFixedMessage(): void
    {
        $cause = new RuntimeException('synthetic-private-detail');
        foreach ([
            [new InvalidTelegramUpdatePayloadException($cause), 'invalid_telegram_update_payload'],
            [new TelegramUpdateAcceptanceIntegrityException($cause), 'telegram_update_acceptance_integrity_failure'],
        ] as [$exception, $message]) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    private function newInbox(): ITelegramInboxStore
    {
        $store = $this->createMock(ITelegramInboxStore::class);
        $store->expects(self::once())->method('reserve')->willReturn(
            new TelegramInboxReservation(self::INBOX_ID, TelegramInboxReservationOutcome::CREATED),
        );
        return $store;
    }

    private function runner(): ITelegramInboxTransactionRunner
    {
        $runner = $this->createMock(ITelegramInboxTransactionRunner::class);
        $runner->expects(self::once())->method('run')->willReturnCallback(static fn (callable $operation): TelegramInboxReservation => $operation());
        return $runner;
    }

    private function generator(string $id = self::CORRELATION_ID): ITelegramAcceptanceIdGenerator
    {
        $generator = $this->createMock(ITelegramAcceptanceIdGenerator::class);
        $generator->expects(self::once())->method('generate')->willReturn($id);
        return $generator;
    }

    private static function command(bool $ignored = false): AcceptTelegramUpdateCommand
    {
        $body = '{"update_id":123}';
        return new AcceptTelegramUpdateCommand(
            'synthetic-bot',
            123,
            $ignored ? IncomingTelegramUpdateType::UNSUPPORTED : IncomingTelegramUpdateType::MESSAGE,
            $ignored ? null : '202',
            $ignored ? TelegramUpdateIgnoreReason::UNSUPPORTED_UPDATE_TYPE : null,
            $body,
            hash('sha256', $body),
        );
    }
}
