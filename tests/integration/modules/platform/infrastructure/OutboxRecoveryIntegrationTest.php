<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IRetryJitter;
use tests\fixtures\platform\TestOutboxRoutes;
use modules\platform\infrastructure\db\DbOutboxRecoveryStore;
use modules\platform\infrastructure\db\DbOutboxRelayStore;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\platform\infrastructure\db\OutboxRelayRowMapper;
use modules\platform\infrastructure\identity\RamseyOutboxLeaseTokenGenerator;
use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;
use Yii;
use yii\db\Connection;
use yii\db\Expression;

final class OutboxRecoveryIntegrationTest extends Unit
{
    private Connection $db;
    private ?Connection $other = null;
    /** @var list<string> */
    private array $ownedIds = [];

    protected function _before(): void
    {
        self::assertTrue(defined('YII_ENV_TEST') && constant('YII_ENV_TEST') === true);
        self::assertSame('test', getenv('APP_ENV'));
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertSame('ideakit_test', $db->createCommand('SELECT current_database()')->queryScalar());
        self::assertSame(0, (int) $db->createCommand('SELECT count(*) FROM {{%outbox_messages}}')->queryScalar());
        $this->db = $db;
    }

    protected function _after(): void
    {
        if ($this->other !== null) {
            $this->other->getTransaction()?->rollBack();
            $this->other->close();
        }
        if (isset($this->db)) {
            $this->db->getTransaction()?->rollBack();
            if ($this->db->pdo?->inTransaction()) {
                $this->db->pdo->rollBack();
            }
            $this->db->createCommand('SET search_path = public')->execute();
            if ($this->ownedIds !== []) {
                $this->db->createCommand()->delete('{{%outbox_messages}}', ['id' => $this->ownedIds])->execute();
            }
        }
    }

    public function testRecoversExpiredLeaseOnceAndFencesPreviousOwner(): void
    {
        $id = $this->insert();
        $claim = $this->claimAndExpire($id);
        $before = $this->row($id);

        $receipt = $this->recovery()->recoverExpired(10, self::settings());

        self::assertSame(1, $receipt->retryScheduled);
        self::assertSame(0, $receipt->failed);
        self::assertNull($this->db->getTransaction());
        $row = $this->row($id, $this->otherConnection());
        self::assertSame('RETRY_SCHEDULED', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame('lease_expired', $row['last_error_code']);
        self::assertNull($row['locked_by']);
        self::assertNull($row['locked_until']);
        self::assertSame($before['payload'], $row['payload']);
        self::assertSame($before['idempotency_key'], $row['idempotency_key']);
        self::assertSame(15, (int) $this->db->createCommand(
            'SELECT EXTRACT(EPOCH FROM next_attempt_at - updated_at) FROM {{%outbox_messages}} WHERE id = :id',
            [':id' => $id],
        )->queryScalar());
        $history = json_decode($row['attempt_history'], true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(1, $history['attempts']);
        self::assertSame(1, $history['attempts'][0]['attempt_no']);
        self::assertSame('RETRY_SCHEDULED', $history['attempts'][0]['outcome']);
        self::assertSame('lease_expired', $history['attempts'][0]['error_code']);
        self::assertEquals(new DateTimeImmutable($before['updated_at']), new DateTimeImmutable($history['attempts'][0]['started_at']));
        self::assertFalse($this->relay()->finish($claim, OutboxRelayDecision::delivered()));
        self::assertSame($row, $this->row($id));
        self::assertSame(0, $this->recovery()->recoverExpired(10, self::settings())->retryScheduled);
    }

    public function testLeavesActiveLeaseAndOtherDestinationUntouched(): void
    {
        $active = $this->insert();
        $this->relay()->claimNext(self::settings());
        $otherDestination = $this->insert();
        $this->claimAndExpire($otherDestination);
        $this->db->createCommand()->update('{{%outbox_messages}}', ['destination' => 'TELEGRAM'], ['id' => $otherDestination])->execute();
        $activeBefore = $this->row($active);
        $otherBefore = $this->row($otherDestination);

        $receipt = $this->recovery()->recoverExpired(10, self::settings());

        self::assertSame(0, $receipt->retryScheduled);
        self::assertSame(0, $receipt->failed);
        self::assertSame($activeBefore, $this->row($active));
        self::assertSame($otherBefore, $this->row($otherDestination));
    }

    public function testSkipsLockedRowAndProcessesEachEligibleRowAtMostOnce(): void
    {
        $first = $this->insert();
        $second = $this->insert();
        $this->claimAndExpire($first, '2000-01-01T00:00:00Z');
        $this->claimAndExpire($second, '2001-01-01T00:00:00Z');
        $transaction = $this->db->beginTransaction();
        $this->db->createCommand('SELECT id FROM {{%outbox_messages}} WHERE id = :id FOR UPDATE', [':id' => $first])->queryScalar();

        $other = $this->otherConnection();
        $other->createCommand('SET statement_timeout = 2000')->execute();
        $receipt = $this->recovery($other)->recoverExpired(1, self::settings());
        self::assertSame(1, $receipt->retryScheduled);
        self::assertSame('PROCESSING', $this->row($first)['status']);
        self::assertSame('RETRY_SCHEDULED', $this->row($second, $other)['status']);
        $transaction->commit();

        self::assertSame(1, $this->recovery($other)->recoverExpired(10, self::settings())->retryScheduled);
        self::assertSame(0, $this->recovery($other)->recoverExpired(10, self::settings())->retryScheduled);
        self::assertSame('RETRY_SCHEDULED', $this->row($first)['status']);
    }

    public function testExhaustedAttemptBecomesFailedWithoutPublishing(): void
    {
        $id = $this->insert();
        $this->claimAndExpire($id, settings: new OutboxRelaySettings(1, 600, 15, 900));

        $receipt = $this->recovery()->recoverExpired(10, new OutboxRelaySettings(1, 600, 15, 900));

        self::assertSame(0, $receipt->retryScheduled);
        self::assertSame(1, $receipt->failed);
        $row = $this->row($id);
        self::assertSame('FAILED', $row['status']);
        self::assertSame('lease_expired', $row['last_error_code']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertNull($row['next_attempt_at']);
        self::assertNotNull($row['payload']);
        self::assertCount(1, json_decode($row['attempt_history'], true, 16, JSON_THROW_ON_ERROR)['attempts']);
    }

    public function testReducedAttemptBudgetDoesNotInventAnotherAttempt(): void
    {
        $id = $this->insert();
        $claim = $this->relay()->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        self::assertTrue($this->relay()->finish($claim, OutboxRelayDecision::retry(OutboxRelayError::CONFIRM_TIMEOUT, 15)));
        $this->db->createCommand()->update('{{%outbox_messages}}', ['next_attempt_at' => '2000-01-01T00:00:00Z'], ['id' => $id])->execute();
        $newLimit = new OutboxRelaySettings(1, 600, 15, 900);
        $claim = $this->relay()->claimNext($newLimit);
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        self::assertFalse($claim->attemptStarted);
        $this->db->createCommand()->update('{{%outbox_messages}}', ['locked_until' => '2000-01-01T00:00:00Z'], ['id' => $id])->execute();
        $before = $this->row($id);

        $receipt = $this->recovery()->recoverExpired(10, $newLimit);

        self::assertSame(1, $receipt->failed);
        $row = $this->row($id);
        self::assertSame('FAILED', $row['status']);
        self::assertSame('attempt_limit_reached', $row['last_error_code']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame($before['attempt_history'], $row['attempt_history']);
        self::assertSame($before['payload'], $row['payload']);
    }

    public function testMalformedHistoryFailsClosedWithoutChangingPayload(): void
    {
        $id = $this->insert();
        $this->claimAndExpire($id);
        $this->db->createCommand()->update('{{%outbox_messages}}', [
            'attempt_history' => new Expression('CAST(:history AS jsonb)', [':history' => '{"schema_version":"2.0","attempts":[]}']),
        ], ['id' => $id])->execute();
        $before = $this->row($id);

        $receipt = $this->recovery()->recoverExpired(10, self::settings());

        self::assertSame(1, $receipt->failed);
        $row = $this->row($id);
        self::assertSame('FAILED', $row['status']);
        self::assertSame('invalid_message', $row['last_error_code']);
        self::assertSame($before['attempt_history'], $row['attempt_history']);
        self::assertSame($before['payload'], $row['payload']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame(0, $this->recovery()->recoverExpired(10, self::settings())->failed);
    }

    public function testAlreadyCompletedAttemptWithRemainingBudgetIsInvalid(): void
    {
        $id = $this->insert();
        $this->claimAndExpire($id);
        $history = '{"schema_version":"1.0","attempts":[{"attempt_no":1,'
            . '"started_at":"2026-09-28T06:00:00.000000Z",'
            . '"finished_at":"2026-09-28T06:00:01.000000Z",'
            . '"outcome":"RETRY_SCHEDULED","error_code":"confirm_timeout"}]}';
        $this->db->createCommand()->update('{{%outbox_messages}}', [
            'attempt_history' => new Expression('CAST(:history AS jsonb)', [':history' => $history]),
        ], ['id' => $id])->execute();

        self::assertSame(1, $this->recovery()->recoverExpired(10, self::settings())->failed);
        $row = $this->row($id);
        self::assertSame('invalid_message', $row['last_error_code']);
        self::assertCount(1, json_decode($row['attempt_history'], true, 16, JSON_THROW_ON_ERROR)['attempts']);
    }

    /** @dataProvider callerTransactions */
    public function testCallerTransactionIsRejectedWithoutMutation(bool $rawPdo): void
    {
        $id = $this->insert();
        $this->claimAndExpire($id);
        $before = $this->row($id);
        if ($rawPdo) {
            $this->db->pdo->beginTransaction();
        } else {
            $this->db->beginTransaction();
        }

        try {
            $this->recovery()->recoverExpired(10, self::settings());
            self::fail('Expected caller transaction rejection.');
        } catch (OutboxMaintenanceException $exception) {
            self::assertSame(OutboxMaintenanceError::TRANSACTION_ALREADY_ACTIVE, $exception->error);
            self::assertNull($exception->getPrevious());
        }
        self::assertSame($before, $this->row($id));
    }

    /** @return iterable<string, array{bool}> */
    public static function callerTransactions(): iterable
    {
        yield 'Yii transaction' => [false];
        yield 'PDO transaction' => [true];
    }

    public function testDatabaseFailureDoesNotExposeDiagnosticOrChangeRow(): void
    {
        $id = $this->insert();
        $this->claimAndExpire($id);
        $before = $this->row($id);
        $this->db->createCommand('SET search_path = pg_catalog')->execute();

        try {
            $this->recovery()->recoverExpired(10, self::settings());
            self::fail('Expected persistence failure.');
        } catch (OutboxMaintenanceException $exception) {
            self::assertSame(OutboxMaintenanceError::PERSISTENCE_FAILURE, $exception->error);
            self::assertSame('persistence_failure', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        } finally {
            $this->db->createCommand('SET search_path = public')->execute();
        }
        self::assertNull($this->db->getTransaction());
        self::assertSame($before, $this->row($id));
    }

    public function testCommitFailureRollsBackRecoveredRow(): void
    {
        $id = $this->insert();
        $this->claimAndExpire($id);
        $before = $this->row($id);
        $other = $this->otherConnection();
        $other->close();
        $other->pdo = new class ($other->dsn, $other->username, $other->password) extends PDO {
            public function commit(): bool
            {
                throw new PDOException('Synthetic commit diagnostic.');
            }
        };
        self::assertSame('ideakit_test', $other->createCommand('SELECT current_database()')->queryScalar());

        try {
            $this->recovery($other)->recoverExpired(10, self::settings());
            self::fail('Expected persistence failure.');
        } catch (OutboxMaintenanceException $exception) {
            self::assertSame(OutboxMaintenanceError::PERSISTENCE_FAILURE, $exception->error);
            self::assertNotNull($exception->getPrevious());
        }
        self::assertFalse($other->pdo->inTransaction());
        self::assertSame($before, $this->row($id));
    }

    /** @param array<string, mixed> $changes */
    private function insert(array $changes = []): string
    {
        $transaction = $this->db->beginTransaction();
        $updateId = Uuid::uuid7()->toString();
        $receipt = (new DbOutboxWriter($this->db, TestOutboxRoutes::registry()))->write(new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $updateId,
            new TelegramUpdateReceivedPayload($updateId),
            'recovery-test:' . $updateId,
            $updateId,
        ));
        $this->ownedIds[] = $receipt->outboxMessageId;
        $this->db->createCommand()->update('{{%outbox_messages}}', array_replace([
            'created_at' => sprintf('2000-01-%02dT00:00:00Z', count($this->ownedIds)),
        ], $changes), ['id' => $receipt->outboxMessageId])->execute();
        $transaction->commit();

        return $receipt->outboxMessageId;
    }

    private function claimAndExpire(
        string $id,
        string $until = '2000-01-01T00:00:00Z',
        ?OutboxRelaySettings $settings = null,
    ): OutboxRelayClaim {
        $claim = $this->relay()->claimNext($settings ?? self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        self::assertSame($id, $claim->outboxId);
        $this->db->createCommand()->update('{{%outbox_messages}}', ['locked_until' => $until], ['id' => $id])->execute();

        return $claim;
    }

    private function relay(): DbOutboxRelayStore
    {
        return new DbOutboxRelayStore(
            $this->db,
            new OutboxRelayRowMapper(TestOutboxRoutes::registry()),
            new RamseyOutboxLeaseTokenGenerator(),
        );
    }

    private function recovery(?Connection $db = null): DbOutboxRecoveryStore
    {
        $settings = self::settings();
        $jitter = new class () implements IRetryJitter {
            public function between(int $minimum, int $maximum): int
            {
                return $minimum;
            }
        };

        return new DbOutboxRecoveryStore(
            $db ?? $this->db,
            new OutboxRelayRowMapper(TestOutboxRoutes::registry()),
            new OutboxRetryPolicy($settings, $jitter),
        );
    }

    private function otherConnection(): Connection
    {
        if ($this->other === null) {
            $this->other = new Connection([
                'dsn' => $this->db->dsn, 'username' => $this->db->username,
                'password' => $this->db->password, 'tablePrefix' => $this->db->tablePrefix,
            ]);
            self::assertSame('ideakit_test', $this->other->createCommand('SELECT current_database()')->queryScalar());
        }

        return $this->other;
    }

    /** @return array<string, mixed> */
    private function row(string $id, ?Connection $db = null): array
    {
        $row = ($db ?? $this->db)->createCommand('SELECT * FROM {{%outbox_messages}} WHERE id = :id', [':id' => $id])->queryOne();
        self::assertIsArray($row);

        return $row;
    }

    private static function settings(): OutboxRelaySettings
    {
        return new OutboxRelaySettings(5, 600, 15, 900);
    }
}
