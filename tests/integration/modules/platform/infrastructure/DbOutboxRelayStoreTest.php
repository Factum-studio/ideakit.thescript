<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use tests\fixtures\platform\PlatformTestEnvironment;
use Codeception\Test\Unit;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\port\IOutboxLeaseTokenGenerator;
use tests\fixtures\platform\TestOutboxRoutes;
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

final class DbOutboxRelayStoreTest extends Unit
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

    public function testClaimCommitsOneStartedAttemptBeforeReturning(): void
    {
        $first = $this->insert();
        $second = $this->insert();
        $claim = $this->store()->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        self::assertSame($first, $claim->outboxId);
        self::assertTrue($claim->attemptStarted);
        self::assertNull($this->db->getTransaction());
        $row = $this->row($first, $this->otherConnection());
        self::assertSame('PROCESSING', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame($claim->leaseToken, $row['locked_by']);
        self::assertSame(600, $claim->leaseUntil->getTimestamp() - $claim->claimedAt->getTimestamp());
        self::assertTrue($this->store()->isLeaseActive($claim));
        self::assertSame('PENDING', $this->row($second)['status']);
    }

    /**
     * @dataProvider eligibility
     * @param array<string, mixed> $changes
     */
    public function testSelectsOnlyReadyRabbitMqRows(array $changes, bool $eligible): void
    {
        $id = $this->insert($changes);
        $claim = $this->store()->claimNext(self::settings());
        self::assertSame($eligible ? $id : null, $claim?->outboxId);
        if ($eligible) {
            self::assertNull($this->row($id)['next_attempt_at']);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function eligibility(): iterable
    {
        yield 'due retry' => [['status' => 'RETRY_SCHEDULED', 'next_attempt_at' => '2000-01-01T00:00:00Z'], true];
        yield 'future retry' => [['status' => 'RETRY_SCHEDULED', 'next_attempt_at' => '2999-01-01T00:00:00Z'], false];
        yield 'telegram' => [['destination' => 'TELEGRAM'], false];
        yield 'delivered' => [['status' => 'DELIVERED', 'delivered_at' => '2000-01-01T00:00:00Z'], false];
        yield 'failed' => [['status' => 'FAILED'], false];
        foreach (['2000-01-01T00:00:00Z', '2999-01-01T00:00:00Z'] as $until) {
            yield 'processing ' . $until => [['status' => 'PROCESSING', 'locked_by' => 'synthetic-owner', 'locked_until' => $until], false];
        }
    }

    /** @dataProvider callerTransactions */
    public function testRejectsCallerTransactionWithoutCapturingOrFinalizing(bool $rawPdo): void
    {
        $id = $this->insert();
        $store = $this->store();
        $claim = $store->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        $before = $this->row($id);
        if ($rawPdo) {
            $this->db->pdo->beginTransaction();
        } else {
            $this->db->beginTransaction();
        }
        foreach (['claim', 'finish'] as $operation) {
            try {
                if ($operation === 'claim') {
                    $store->claimNext(self::settings());
                } else {
                    $store->finish($claim, OutboxRelayDecision::delivered());
                }
                self::fail('Expected caller transaction rejection.');
            } catch (OutboxRelayException $exception) {
                self::assertSame(OutboxRelayError::TRANSACTION_ALREADY_ACTIVE, $exception->error);
                self::assertNull($exception->getPrevious());
            }
        }
        self::assertSame($before, $this->row($id));
    }

    /** @return iterable<string, array{bool}> */
    public static function callerTransactions(): iterable
    {
        yield 'yii transaction' => [false];
        yield 'pdo transaction' => [true];
    }

    public function testSkipsRowLockedByAnotherConnectionAndDoesNotClaimProcessingAgain(): void
    {
        $first = $this->insert();
        $second = $this->insert();
        $transaction = $this->db->beginTransaction();
        $this->db->createCommand('SELECT id FROM {{%outbox_messages}} WHERE id = :id FOR UPDATE', [':id' => $first])->queryScalar();
        $other = $this->otherConnection();
        $other->createCommand('SET statement_timeout = 2000')->execute();
        $claim = $this->store($other)->claimNext(self::settings());
        self::assertSame($second, $claim?->outboxId);
        $transaction->commit();
        self::assertSame($first, $this->store($other)->claimNext(self::settings())?->outboxId);
        self::assertNull($this->store($other)->claimNext(self::settings()));
    }

    public function testUncommittedWriterIntentIsInvisibleAndRollbackLeavesNoClaim(): void
    {
        $transaction = $this->db->beginTransaction();
        $this->insert(commit: false);
        self::assertNull($this->store($this->otherConnection())->claimNext(self::settings()));
        $transaction->rollBack();
        self::assertNull($this->store()->claimNext(self::settings()));
    }

    public function testFailedClaimRollsBackWithoutConsumingAttemptOrRetainingLock(): void
    {
        $id = $this->insert();
        $tokens = new class () implements IOutboxLeaseTokenGenerator {
            public function generate(): string
            {
                throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE);
            }
        };
        try {
            $this->store(tokens: $tokens)->claimNext(self::settings());
            self::fail('Expected token generation failure.');
        } catch (OutboxRelayException $exception) {
            self::assertSame(OutboxRelayError::UNEXPECTED_FAILURE, $exception->error);
        }
        self::assertNull($this->db->getTransaction());
        self::assertSame(0, (int) $this->row($id)['attempt_count']);
        self::assertNull($this->row($id)['locked_by']);
        self::assertSame($id, $this->store($this->otherConnection())->claimNext(self::settings())?->outboxId);
    }

    /** @dataProvider commitOperations */
    public function testCommitFailureRemainsPrimaryWhenRollbackAlsoFails(string $operation): void
    {
        $id = $this->insert();
        $claim = $operation === 'finish' ? $this->store()->claimNext(self::settings()) : null;
        $before = $this->row($id);
        $other = $this->otherConnection();
        $other->close();
        $other->pdo = new class ($other->dsn, $other->username, $other->password) extends PDO {
            public function commit(): bool
            {
                throw new PDOException('Synthetic commit failure.');
            }

            public function rollBack(): bool
            {
                parent::rollBack();
                throw new PDOException('Synthetic rollback failure.');
            }
        };
        self::assertSame('ideakit_test', $other->createCommand('SELECT current_database()')->queryScalar());
        try {
            if ($operation === 'claim') {
                $this->store($other)->claimNext(self::settings());
            } else {
                self::assertInstanceOf(OutboxRelayClaim::class, $claim);
                $this->store($other)->finish($claim, OutboxRelayDecision::delivered());
            }
            self::fail('Expected commit failure.');
        } catch (OutboxRelayException $exception) {
            self::assertSame(OutboxRelayError::PERSISTENCE_FAILURE, $exception->error);
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame('Synthetic commit failure.', $exception->getPrevious()->getMessage());
            self::assertSame('persistence_failure', $exception->getMessage());
        }
        self::assertFalse($other->pdo->inTransaction());
        self::assertSame($before, $this->row($id));
    }

    /** @return iterable<string, array{string}> */
    public static function commitOperations(): iterable
    {
        yield 'claim' => ['claim'];
        yield 'finish' => ['finish'];
    }

    /** @dataProvider outcomes */
    public function testFinalizesStateAndHistoryAtomically(string $status): void
    {
        $id = $this->insert();
        $store = $this->store();
        $claim = $store->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        $before = $this->row($id);
        $decision = match ($status) {
            'DELIVERED' => OutboxRelayDecision::delivered(),
            'RETRY_SCHEDULED' => OutboxRelayDecision::retry(OutboxRelayError::CONFIRM_TIMEOUT, 15),
            'FAILED' => OutboxRelayDecision::failed(OutboxRelayError::INVALID_MESSAGE),
            default => self::fail('Unexpected outcome test case.'),
        };
        self::assertTrue($store->finish($claim, $decision));
        self::assertNull($this->db->getTransaction());
        $row = $this->row($id, $this->otherConnection());
        self::assertSame($status, $row['status']);
        self::assertNull($row['locked_by']);
        self::assertNull($row['locked_until']);
        self::assertNull($row['external_reference']);
        self::assertSame($decision->error?->value, $row['last_error_code']);
        foreach (['id', 'owner_module', 'destination', 'routing_key', 'message_type', 'schema_version',
            'aggregate_type', 'aggregate_id', 'payload', 'payload_hash', 'idempotency_key', 'correlation_id', 'created_at',
        ] as $field) {
            self::assertSame($before[$field], $row[$field]);
        }
        $history = json_decode($row['attempt_history'], true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(1, $history['attempts']);
        self::assertSame(1, $history['attempts'][0]['attempt_no']);
        self::assertSame($status, $history['attempts'][0]['outcome']);
        self::assertSame($decision->error?->value, $history['attempts'][0]['error_code']);
        self::assertSame(0, (int) $this->db->createCommand(
            'SELECT count(*) FROM {{%outbox_messages}} WHERE id = :id AND ('
            . '(status = \'DELIVERED\' AND (delivered_at <> updated_at OR payload_expires_at <> delivered_at + INTERVAL \'30 days\'))'
            . ' OR (status = \'RETRY_SCHEDULED\' AND next_attempt_at <> updated_at + INTERVAL \'15 seconds\'))',
            [':id' => $id],
        )->queryScalar());
        self::assertFalse($store->finish($claim, $decision));
    }

    /** @return iterable<string, array{string}> */
    public static function outcomes(): iterable
    {
        foreach (['DELIVERED', 'RETRY_SCHEDULED', 'FAILED'] as $status) {
            yield $status => [$status];
        }
    }

    /** @dataProvider lostOwnership */
    public function testLateOwnerCannotChangeRow(string $case): void
    {
        $id = $this->insert();
        $store = $this->store();
        $claim = $store->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        $this->db->createCommand()->update('{{%outbox_messages}}', $case === 'token'
            ? ['locked_by' => 'synthetic-new-owner']
            : ['locked_until' => '2000-01-01T00:00:00Z'], ['id' => $id])->execute();
        $before = $this->row($id);
        self::assertFalse($store->isLeaseActive($claim));
        self::assertFalse($store->finish($claim, OutboxRelayDecision::delivered()));
        self::assertSame($before, $this->row($id));
    }

    /** @return iterable<string, array{string}> */
    public static function lostOwnership(): iterable
    {
        yield 'changed token' => ['token'];
        yield 'expired lease' => ['expired'];
    }

    public function testExhaustedAndCorruptMessagesCanBeTerminatedWithoutAnotherSend(): void
    {
        $exhausted = $this->insert(['attempt_count' => 5]);
        $corrupt = $this->insert(['payload_hash' => str_repeat('b', 64)]);
        $store = $this->store();
        $claim = $store->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        self::assertSame($exhausted, $claim->outboxId);
        self::assertFalse($claim->attemptStarted);
        self::assertSame(OutboxRelayError::ATTEMPT_LIMIT_REACHED, $claim->rejection);
        self::assertTrue($store->finish($claim, OutboxRelayDecision::failed($claim->rejection)));
        self::assertSame(5, (int) $this->row($exhausted)['attempt_count']);
        self::assertSame([], json_decode($this->row($exhausted)['attempt_history'], true, 16, JSON_THROW_ON_ERROR)['attempts']);
        $claim = $store->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        self::assertSame($corrupt, $claim->outboxId);
        self::assertSame(OutboxRelayError::INVALID_MESSAGE, $claim->rejection);
        self::assertTrue($store->finish($claim, OutboxRelayDecision::failed($claim->rejection)));
        self::assertNull($store->claimNext(self::settings()));
    }

    public function testUnknownHistoryIsPreservedWhileMessageBecomesFailed(): void
    {
        $history = '{"schema_version":"2.0","attempts":[]}';
        $id = $this->insert(['attempt_history' => new Expression('CAST(:history AS jsonb)', [':history' => $history])]);
        $before = $this->row($id)['attempt_history'];
        $store = $this->store();
        $claim = $store->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        self::assertSame(OutboxRelayError::INVALID_MESSAGE, $claim->rejection);
        self::assertTrue($store->finish($claim, OutboxRelayDecision::failed($claim->rejection)));
        self::assertSame($before, $this->row($id)['attempt_history']);
    }

    public function testRetryUsesNewTokenAndPreservesCompletedHistoryAndMessageIdentity(): void
    {
        $id = $this->insert();
        $this->db->createCommand("SET TIME ZONE 'Europe/Berlin'")->execute();
        try {
            $store = $this->store();
            $first = $store->claimNext(self::settings());
            self::assertInstanceOf(OutboxRelayClaim::class, $first);
            self::assertTrue($store->finish($first, OutboxRelayDecision::retry(OutboxRelayError::CONFIRM_TIMEOUT, 15)));
            $history = json_decode($this->row($id)['attempt_history'], true, 16, JSON_THROW_ON_ERROR);
            $this->db->createCommand()->update('{{%outbox_messages}}', [
                'next_attempt_at' => '2000-01-01T00:00:00Z',
            ], ['id' => $id])->execute();
            $second = $store->claimNext(self::settings());
            self::assertInstanceOf(OutboxRelayClaim::class, $second);
            self::assertNotSame($first->leaseToken, $second->leaseToken);
            self::assertEquals($first->envelope, $second->envelope);
            self::assertSame('UTC', $second->claimedAt->getTimezone()->getName());
            self::assertTrue($store->finish($second, OutboxRelayDecision::delivered()));
            $after = json_decode($this->row($id)['attempt_history'], true, 16, JSON_THROW_ON_ERROR);
            self::assertCount(2, $after['attempts']);
            self::assertSame($history['attempts'][0], $after['attempts'][0]);
            self::assertSame(2, $after['attempts'][1]['attempt_no']);
        } finally {
            $this->db->createCommand("SET TIME ZONE 'UTC'")->execute();
        }
    }

    public function testFinalizationRechecksLeaseAfterWaitingForRowLock(): void
    {
        $id = $this->insert();
        $claim = $this->store()->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        $transaction = $this->db->beginTransaction();
        $this->db->createCommand('SELECT id FROM {{%outbox_messages}} WHERE id = :id FOR UPDATE', [':id' => $id])->queryScalar();
        $pipes = [];
        $process = proc_open([
            PHP_BINARY, '-r', self::CHILD_FINISH,
            json_encode([
                $claim->outboxId, $claim->leaseToken, $claim->attemptNumber,
                $claim->claimedAt->format('Y-m-d\TH:i:s.uP'), $claim->leaseUntil->format('Y-m-d\TH:i:s.uP'),
                $claim->envelope?->correlationId, $claim->envelope?->payload->technicalFields()['update_id'],
            ], JSON_THROW_ON_ERROR),
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 5), PlatformTestEnvironment::databaseEnvironment());
        self::assertIsResource($process);
        try {
            fclose($pipes[0]);
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            self::assertSame(1, stream_select($read, $write, $except, 5));
            self::assertSame("READY\n", fgets($pipes[1]));
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) $this->db->createCommand(
                    "SELECT count(*) FROM pg_stat_activity WHERE application_name = 'platform-relay-finish-test' AND wait_event_type = 'Lock'",
                )->queryScalar() === 1;
            } while (!$waiting && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Finalization must be waiting for the row lock.');
            $this->db->createCommand()->update('{{%outbox_messages}}', [
                'locked_until' => '2000-01-01T00:00:00Z',
            ], ['id' => $id])->execute();
            $before = $this->row($id);
            $transaction->commit();
            stream_set_timeout($pipes[1], 10);
            self::assertSame('LOST', trim(stream_get_contents($pipes[1])));
            self::assertSame(0, proc_close($process));
            $process = null;
            self::assertSame($before, $this->row($id));
        } finally {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ([1, 2] as $pipe) {
                if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
                    fclose($pipes[$pipe]);
                }
            }
        }
    }

    private const CHILD_FINISH = <<<'PHP'
require getcwd() . '/vendor/yiisoft/yii2/Yii.php';
require getcwd() . '/vendor/autoload.php';
if (getenv('APP_ENV') !== 'test') { exit(2); }
$db = new yii\db\Connection([
    'dsn' => getenv('TEST_DB_DSN'), 'username' => getenv('TEST_DB_USERNAME'), 'password' => getenv('TEST_DB_PASSWORD'),
]);
if ($db->driverName !== 'pgsql' || $db->createCommand('SELECT current_database()')->queryScalar() !== 'ideakit_test') { exit(3); }
$db->createCommand("SET application_name = 'platform-relay-finish-test'")->execute();
$db->createCommand('SET statement_timeout = 10000')->execute();
[$id, $token, $attempt, $started, $until, $correlation, $update] = json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR);
$claim = new modules\platform\application\dto\OutboxRelayClaim(
    $id, $token, $attempt, true, new DateTimeImmutable($started), new DateTimeImmutable($until),
    new modules\platform\application\dto\BrokerEnvelope(
        $id, 'telegram.update.received', '1.0', $correlation,
        new modules\telegram\application\message\TelegramUpdateReceivedPayload($update),
    ), null,
);
$store = new modules\platform\infrastructure\db\DbOutboxRelayStore(
    $db, new modules\platform\infrastructure\db\OutboxRelayRowMapper(tests\fixtures\platform\TestOutboxRoutes::registry()),
    new modules\platform\infrastructure\identity\RamseyOutboxLeaseTokenGenerator(),
);
echo "READY\n";
flush();
echo $store->finish($claim, modules\platform\application\dto\OutboxRelayDecision::delivered()) ? 'SAVED' : 'LOST';
PHP;

    public function testDatabaseFailureExposesOnlySafeCodeAndLeavesClaimDurable(): void
    {
        $id = $this->insert();
        $store = $this->store();
        $claim = $store->claimNext(self::settings());
        self::assertInstanceOf(OutboxRelayClaim::class, $claim);
        $before = $this->row($id);
        $this->db->createCommand('SET search_path = pg_catalog')->execute();
        foreach (['claim', 'check', 'finish'] as $operation) {
            try {
                match ($operation) {
                    'claim' => $store->claimNext(self::settings()),
                    'check' => $store->isLeaseActive($claim),
                    default => $store->finish($claim, OutboxRelayDecision::delivered()),
                };
                self::fail('Expected persistence failure.');
            } catch (OutboxRelayException $exception) {
                self::assertSame(OutboxRelayError::PERSISTENCE_FAILURE, $exception->error);
                self::assertNotNull($exception->getPrevious());
                self::assertSame('persistence_failure', $exception->getMessage());
                self::assertNull($this->db->getTransaction());
            }
        }
        $this->db->createCommand('SET search_path = public')->execute();
        self::assertSame($before, $this->row($id));
    }

    /** @param array<string, mixed> $changes */
    private function insert(array $changes = [], bool $commit = true): string
    {
        $transaction = $commit ? $this->db->beginTransaction() : null;
        $updateId = Uuid::uuid7()->toString();
        $receipt = (new DbOutboxWriter($this->db, TestOutboxRoutes::registry()))->write(new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $updateId,
            new TelegramUpdateReceivedPayload($updateId),
            'relay-test:' . $updateId,
            $updateId,
        ));
        $this->ownedIds[] = $receipt->outboxMessageId;
        $this->db->createCommand()->update('{{%outbox_messages}}', array_replace([
            'created_at' => sprintf('2000-01-%02dT00:00:00Z', count($this->ownedIds)),
        ], $changes), ['id' => $receipt->outboxMessageId])->execute();
        $transaction?->commit();

        return $receipt->outboxMessageId;
    }

    private function store(?Connection $db = null, ?IOutboxLeaseTokenGenerator $tokens = null): DbOutboxRelayStore
    {
        return new DbOutboxRelayStore(
            $db ?? $this->db,
            new OutboxRelayRowMapper(TestOutboxRoutes::registry()),
            $tokens ?? new RamseyOutboxLeaseTokenGenerator(),
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
