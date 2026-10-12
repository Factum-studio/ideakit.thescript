<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\telegram\application\dto\TelegramInboxReservation;
use modules\telegram\application\enum\TelegramInboxReservationOutcome;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use modules\telegram\application\exception\TelegramUpdateAcceptanceIntegrityException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;
use modules\telegram\application\port\ITelegramAcceptanceClock;
use modules\telegram\application\port\ITelegramAcceptanceIdGenerator;
use modules\telegram\application\port\ITelegramInboxTransactionRunner;
use modules\telegram\infrastructure\db\DbTelegramInboxStore;
use modules\telegram\infrastructure\db\DbTelegramInboxTransactionRunner;
use modules\telegram\infrastructure\db\TelegramAcceptanceDbFailure;
use modules\telegram\infrastructure\identity\RamseyTelegramAcceptanceIdGenerator;
use modules\telegram\infrastructure\time\SystemTelegramAcceptanceClock;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use tests\fixtures\platform\TestOutboxRoutes;
use tests\fixtures\telegram\inbox\InboxAcceptanceScenario;
use yii\db\Connection;
use yii\db\Command;
use yii\db\Exception;
use yii\db\Transaction;

final class DbTelegramInboxTransactionRunnerTest extends Unit
{
    private Connection $db;
    private Connection $observer;
    private string $botKey;

    protected function _before(): void
    {
        $this->db = InboxAcceptanceScenario::connection();
        $this->observer = InboxAcceptanceScenario::connection();
        $this->botKey = 'transaction-' . Uuid::uuid7()->toString();
    }

    protected function _after(): void
    {
        $this->db->close();
        InboxAcceptanceScenario::cleanup($this->observer, $this->botKey);
        $this->observer->close();
    }

    public function testOwnTransactionIsInvisibleUntilCommitAndSettingsAreLocal(): void
    {
        $settings = $this->settings();
        $runner = new class ($this->runner(), $this) implements ITelegramInboxTransactionRunner {
            public function __construct(private ITelegramInboxTransactionRunner $inner, private DbTelegramInboxTransactionRunnerTest $test)
            {
            }

            public function run(callable $operation): TelegramInboxReservation
            {
                return $this->inner->run(function () use ($operation): TelegramInboxReservation {
                    $result = $operation();
                    $this->test->assertUncommittedPairAndSettings();

                    return $result;
                });
            }
        };
        InboxAcceptanceScenario::handler($this->db, null, $runner)->handle(InboxAcceptanceScenario::command($this->botKey));
        self::assertCount(1, InboxAcceptanceScenario::inbox($this->observer, $this->botKey));
        self::assertCount(1, InboxAcceptanceScenario::outbox($this->observer, $this->botKey));
        self::assertSame($settings, $this->settings());
    }

    public function assertUncommittedPairAndSettings(): void
    {
        self::assertSame([], InboxAcceptanceScenario::inbox($this->observer, $this->botKey));
        self::assertSame([], InboxAcceptanceScenario::outbox($this->observer, $this->botKey));
        self::assertSame(['read committed', 'UTC', '2s', '5s'], $this->settings());
    }

    /** @dataProvider transactionKinds */
    public function testForeignTransactionIsUntouched(bool $rawPdo): void
    {
        $transaction = $rawPdo ? null : $this->db->beginTransaction();
        if ($rawPdo) {
            $this->db->pdo->beginTransaction();
        }
        $store = new DbTelegramInboxStore($this->db, new SystemTelegramAcceptanceClock(), new RamseyTelegramAcceptanceIdGenerator());
        if (!$rawPdo) {
            $store->reserve(InboxAcceptanceScenario::command($this->botKey));
        }
        try {
            $this->runner()->run(static function (): TelegramInboxReservation {
                self::fail('Foreign transaction must reject before callback.');
            });
            self::fail('Expected transaction ownership failure.');
        } catch (TelegramUpdateAcceptanceIntegrityException $exception) {
            self::assertSame('telegram_update_acceptance_integrity_failure', $exception->getMessage());
        }
        self::assertTrue($this->db->getIsActive());
        self::assertTrue($this->db->pdo->inTransaction());
        if ($transaction !== null) {
            self::assertSame($transaction, $this->db->getTransaction());
            self::assertSame(1, $transaction->getLevel());
            self::assertCount(1, InboxAcceptanceScenario::inbox($this->db, $this->botKey));
            $transaction->rollBack();
        } else {
            $this->db->pdo->rollBack();
        }
    }

    /** @return iterable<array{bool}> */
    public static function transactionKinds(): iterable
    {
        yield [false];
        yield [true];
    }

    public function testUnconfirmedCommitReturnsNoReceiptAndRetryFindsCommittedPair(): void
    {
        $runner = new class ($this->runner()) implements ITelegramInboxTransactionRunner {
            public function __construct(private ITelegramInboxTransactionRunner $inner)
            {
            }

            public function run(callable $operation): TelegramInboxReservation
            {
                $this->inner->run($operation);
                throw new TelegramUpdateAcceptanceUnavailableException();
            }
        };
        try {
            InboxAcceptanceScenario::handler($this->db, null, $runner)->handle(InboxAcceptanceScenario::command($this->botKey));
            self::fail('Expected unconfirmed acceptance.');
        } catch (TelegramUpdateAcceptanceUnavailableException) {
            self::assertCount(1, InboxAcceptanceScenario::inbox($this->observer, $this->botKey));
        }
        $repeat = InboxAcceptanceScenario::handler($this->db)->handle(InboxAcceptanceScenario::command($this->botKey));
        self::assertSame(TelegramUpdateAcceptanceOutcome::DUPLICATE, $repeat->outcome);
        self::assertCount(1, InboxAcceptanceScenario::outbox($this->observer, $this->botKey));
    }

    public function testFailureDuringActualYiiCommitClosesConnectionWithoutCompensation(): void
    {
        $failure = new RuntimeException('synthetic_commit_failure');
        $this->db->on(Connection::EVENT_COMMIT_TRANSACTION, static function () use ($failure): void {
            throw $failure;
        });
        try {
            InboxAcceptanceScenario::handler($this->db)->handle(InboxAcceptanceScenario::command($this->botKey));
            self::fail('Expected commit failure.');
        } catch (TelegramUpdateAcceptanceUnavailableException $exception) {
            self::assertSame($failure, $exception->getPrevious());
            self::assertFalse($this->db->getIsActive());
            self::assertCount(1, InboxAcceptanceScenario::inbox($this->observer, $this->botKey));
            self::assertCount(1, InboxAcceptanceScenario::outbox($this->observer, $this->botKey));
        }
    }

    public function testCallbackLeavingNestedTransactionCannotConfirmAcceptance(): void
    {
        $runner = new class ($this->runner(), $this->db) implements ITelegramInboxTransactionRunner {
            public function __construct(private ITelegramInboxTransactionRunner $inner, private Connection $db)
            {
            }

            public function run(callable $operation): TelegramInboxReservation
            {
                return $this->inner->run(function () use ($operation): TelegramInboxReservation {
                    $result = $operation();
                    $this->db->beginTransaction();

                    return $result;
                });
            }
        };
        try {
            InboxAcceptanceScenario::handler($this->db, null, $runner)->handle(InboxAcceptanceScenario::command($this->botKey));
            self::fail('Expected unfinished transaction failure.');
        } catch (TelegramUpdateAcceptanceIntegrityException) {
            self::assertSame([], InboxAcceptanceScenario::inbox($this->observer, $this->botKey));
            self::assertFalse($this->db->getIsActive());
        }
    }

    /** @dataProvider persistenceFailures */
    public function testDatabaseFailureClassificationPreservesCause(string $state, bool $wrapped, bool $transient): void
    {
        $cause = new Exception('synthetic_database_failure', [$state]);
        $failure = $wrapped ? new OutboxWriteException(OutboxWriteFailure::PERSISTENCE_FAILURE, $cause) : $cause;
        try {
            $this->runner()->run(static fn (): TelegramInboxReservation => throw $failure);
            self::fail('Expected persistence failure.');
        } catch (TelegramUpdateAcceptanceUnavailableException | TelegramUpdateAcceptanceIntegrityException $exception) {
            self::assertSame($failure, $exception->getPrevious());
            self::assertSame($transient, $exception instanceof TelegramUpdateAcceptanceUnavailableException);
            self::assertNull($this->db->getTransaction());
        }
    }

    /** @return iterable<array{string, bool, bool}> */
    public static function persistenceFailures(): iterable
    {
        yield ['55P03', false, true];
        yield ['40001', true, true];
        yield ['23514', false, false];
        yield ['22003', true, false];
        yield ['22P02', false, false];
    }

    public function testRollbackFailureDoesNotReplaceOriginalCause(): void
    {
        $failure = new RuntimeException('synthetic_primary_failure');
        $this->db->on(Connection::EVENT_ROLLBACK_TRANSACTION, static function (): void {
            throw new RuntimeException('synthetic_cleanup_failure');
        });
        try {
            $this->runner()->run(static fn (): TelegramInboxReservation => throw $failure);
            self::fail('Expected original failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
            self::assertFalse($this->db->getIsActive());
        }
    }

    public function testStoreNormalizesOneInstantAndDoesNotReadClockOrIdsForDuplicate(): void
    {
        $clock = $this->createMock(ITelegramAcceptanceClock::class);
        $clock->expects(self::once())->method('now')->willReturn(new DateTimeImmutable('2026-10-08T17:00:00.123456+05:00'));
        $ids = $this->createMock(ITelegramAcceptanceIdGenerator::class);
        $ids->expects(self::once())->method('generate')->willReturn(Uuid::uuid7()->toString());
        $store = new DbTelegramInboxStore($this->db, $clock, $ids);
        $this->runner()->run(function () use ($store): TelegramInboxReservation {
            $created = $store->reserve(InboxAcceptanceScenario::command($this->botKey));
            $repeat = $store->reserve(InboxAcceptanceScenario::command($this->botKey));
            self::assertSame($created->inboxId, $repeat->inboxId);
            self::assertSame(TelegramInboxReservationOutcome::EXISTING, $repeat->outcome);

            return $created;
        });
        self::assertEquals(new DateTimeImmutable('2026-10-08T12:00:00.123456Z'), new DateTimeImmutable(InboxAcceptanceScenario::inbox($this->db, $this->botKey)[0]['received_at']));
    }

    public function testStoreRejectsMissingTransaction(): void
    {
        $store = new DbTelegramInboxStore($this->db, new SystemTelegramAcceptanceClock(), new RamseyTelegramAcceptanceIdGenerator());
        $this->expectException(TelegramUpdateAcceptanceIntegrityException::class);
        $store->reserve(InboxAcceptanceScenario::command($this->botKey));
    }

    public function testCommitCallFailureClosesOwnedConnectionWithoutRollbackRetry(): void
    {
        $failure = new RuntimeException('synthetic_commit_call_failure');
        $db = $this->getMockBuilder(Connection::class)->onlyMethods(['getTransaction', 'beginTransaction', 'close'])->getMock();
        $db->dsn = $this->db->dsn;
        $db->pdo = $this->db->pdo;
        $db->enableLogging = false;
        $db->enableProfiling = false;
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('getIsActive')->willReturn(true);
        $transaction->method('getLevel')->willReturn(1);
        $transaction->expects(self::once())->method('commit')->willThrowException($failure);
        $transaction->expects(self::never())->method('rollBack');
        $db->method('getTransaction')->willReturnCallback(
            fn (): ?Transaction => $this->db->pdo->inTransaction() ? $transaction : null,
        );
        $db->method('beginTransaction')->willReturnCallback(function () use ($transaction): Transaction {
            $this->db->pdo->beginTransaction();

            return $transaction;
        });
        $db->expects(self::once())->method('close')->willReturnCallback(function () use ($db): void {
            $this->db->pdo->rollBack();
            $db->pdo = null;
        });
        try {
            (new DbTelegramInboxTransactionRunner($db, new TelegramAcceptanceDbFailure()))->run(
                static fn (): TelegramInboxReservation => new TelegramInboxReservation(Uuid::uuid7()->toString(), TelegramInboxReservationOutcome::CREATED),
            );
            self::fail('Expected unavailable commit result.');
        } catch (TelegramUpdateAcceptanceUnavailableException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    /** @dataProvider invalidFallbacks */
    public function testMissingOrInvalidConflictFallbackCannotCreateReceipt(mixed $fallback): void
    {
        $transaction = $this->db->beginTransaction();
        $db = $this->getMockBuilder(Connection::class)->onlyMethods(['getTransaction', 'createCommand'])->getMock();
        $db->pdo = $this->db->pdo;
        $db->method('getTransaction')->willReturn($transaction);
        $query = $this->createMock(Command::class);
        $query->expects(self::exactly(3))->method('queryScalar')->willReturnOnConsecutiveCalls(false, false, $fallback);
        $db->method('createCommand')->willReturn($query);
        try {
            (new DbTelegramInboxStore($db, new SystemTelegramAcceptanceClock(), new RamseyTelegramAcceptanceIdGenerator()))->reserve(InboxAcceptanceScenario::command($this->botKey));
            self::fail('Expected invalid fallback failure.');
        } catch (TelegramUpdateAcceptanceIntegrityException $exception) {
            self::assertSame('telegram_update_acceptance_integrity_failure', $exception->getMessage());
        } finally {
            $transaction->rollBack();
        }
    }

    /** @return iterable<array{mixed}> */
    public static function invalidFallbacks(): iterable
    {
        yield [false];
        yield ['invalid'];
    }

    private function runner(): DbTelegramInboxTransactionRunner
    {
        return new DbTelegramInboxTransactionRunner($this->db, new TelegramAcceptanceDbFailure());
    }

    /** @return list<string> */
    private function settings(): array
    {
        return array_map(fn (string $name): string => (string) $this->db->createCommand('SHOW ' . $name)->queryScalar(), [
            'transaction_isolation', 'timezone', 'lock_timeout', 'statement_timeout',
        ]);
    }
}
