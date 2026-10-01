<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\route\OutboxRouteRegistry;
use modules\platform\infrastructure\db\DbOutboxPayloadCleanupStore;
use modules\platform\infrastructure\db\DbOutboxWriter;
use Ramsey\Uuid\Uuid;
use Yii;
use yii\db\Connection;
use yii\db\Expression;

final class OutboxPayloadCleanupIntegrationTest extends Unit
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

    public function testClearsOnlyDueDeliveredPayloadAndPreservesEvidence(): void
    {
        $due = $this->insert();
        $this->deliver($due, 30);
        $future = $this->insert();
        $this->deliver($future, 29);
        $before = $this->row($due);
        $futureBefore = $this->row($future);

        $receipt = $this->store()->clearDue(10);

        self::assertSame(1, $receipt->cleared);
        $after = $this->row($due, $this->otherConnection());
        self::assertNull($after['payload']);
        self::assertGreaterThanOrEqual(new DateTimeImmutable($before['updated_at']), new DateTimeImmutable($after['updated_at']));
        unset($before['payload'], $before['updated_at'], $after['payload'], $after['updated_at']);
        self::assertSame($before, $after);
        self::assertSame($futureBefore, $this->row($future));
        self::assertSame(0, $this->store()->clearDue(10)->cleared);
    }

    /** @dataProvider unresolvedStatuses */
    public function testDoesNotClearUnresolvedPayload(string $status): void
    {
        $id = $this->insert();
        $changes = ['status' => $status];
        if ($status === 'FAILED') {
            $changes['payload_expires_at'] = new Expression("clock_timestamp() - INTERVAL '1 day'");
        } elseif ($status === 'UNKNOWN') {
            $changes['destination'] = 'TELEGRAM';
        } elseif ($status === 'PROCESSING') {
            $changes['locked_by'] = 'synthetic-owner';
            $changes['locked_until'] = new Expression("clock_timestamp() - INTERVAL '1 day'");
        } elseif ($status === 'RETRY_SCHEDULED') {
            $changes['next_attempt_at'] = new Expression("clock_timestamp() - INTERVAL '1 day'");
        }
        $this->db->createCommand()->update('{{%outbox_messages}}', $changes, ['id' => $id])->execute();
        $before = $this->row($id);

        self::assertSame(0, $this->store()->clearDue(10)->cleared);
        self::assertSame($before, $this->row($id));
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvedStatuses(): iterable
    {
        foreach (['FAILED', 'UNKNOWN', 'PENDING', 'PROCESSING', 'RETRY_SCHEDULED'] as $status) {
            yield $status => [$status];
        }
    }

    public function testSkipsConcurrentLockAndRespectsBatchLimit(): void
    {
        $first = $this->insert();
        $second = $this->insert();
        $this->deliver($first, 32);
        $this->deliver($second, 31);
        $lock = $this->db->beginTransaction();
        $this->db->createCommand('SELECT id FROM {{%outbox_messages}} WHERE id = :id FOR UPDATE', [':id' => $first])->queryScalar();
        $other = $this->otherConnection();
        $other->createCommand('SET statement_timeout = 2000')->execute();

        self::assertSame(1, $this->store($other)->clearDue(1)->cleared);
        self::assertNotNull($this->row($first)['payload']);
        self::assertNull($this->row($second, $other)['payload']);
        $lock->commit();
        self::assertSame(1, $this->store($other)->clearDue(1)->cleared);
        self::assertSame(0, $this->store($other)->clearDue(1)->cleared);
    }

    public function testWriterRemainsIdempotentAfterCleanup(): void
    {
        $id = $this->insert();
        $this->deliver($id, 31);
        self::assertSame(1, $this->store()->clearDue(1)->cleared);
        $before = $this->row($id);
        self::assertNull($before['payload']);
        self::assertSame(
            hash('sha256', '{"update_id":"' . $before['aggregate_id'] . '"}'),
            $before['payload_hash'],
        );

        $transaction = $this->db->beginTransaction();
        try {
            $same = (new DbOutboxWriter($this->db, new OutboxRouteRegistry()))->write($this->intent($before['aggregate_id']));
            self::assertSame(OutboxWriteOutcome::ALREADY_EXISTS, $same->outcome);
            self::assertSame($id, $same->outboxMessageId);
            try {
                (new DbOutboxWriter($this->db, new OutboxRouteRegistry()))->write(
                    $this->intent(Uuid::uuid7()->toString(), $before['aggregate_id']),
                );
                self::fail('Expected idempotency conflict.');
            } catch (OutboxWriteException $exception) {
                self::assertSame(OutboxWriteFailure::IDEMPOTENCY_CONFLICT, $exception->failure);
            }
        } finally {
            $transaction->rollBack();
        }
        self::assertSame($before, $this->row($id));
    }

    public function testRejectsCallerTransactionAndMasksDatabaseFailure(): void
    {
        $id = $this->insert();
        $this->deliver($id, 31);
        $before = $this->row($id);
        $transaction = $this->db->beginTransaction();
        try {
            $this->store()->clearDue(1);
            self::fail('Expected transaction rejection.');
        } catch (OutboxMaintenanceException $exception) {
            self::assertSame(OutboxMaintenanceError::TRANSACTION_ALREADY_ACTIVE, $exception->error);
        } finally {
            $transaction->rollBack();
        }
        $this->db->createCommand('SET search_path = pg_catalog')->execute();
        try {
            $this->store()->clearDue(1);
            self::fail('Expected persistence failure.');
        } catch (OutboxMaintenanceException $exception) {
            self::assertSame(OutboxMaintenanceError::PERSISTENCE_FAILURE, $exception->error);
            self::assertNull($exception->getPrevious());
        } finally {
            $this->db->createCommand('SET search_path = public')->execute();
        }
        self::assertSame($before, $this->row($id));
    }

    private function insert(): string
    {
        $aggregateId = Uuid::uuid7()->toString();
        $transaction = $this->db->beginTransaction();
        $receipt = (new DbOutboxWriter($this->db, new OutboxRouteRegistry()))->write(
            $this->intent($aggregateId),
        );
        $this->ownedIds[] = $receipt->outboxMessageId;
        $transaction->commit();

        return $receipt->outboxMessageId;
    }

    private function deliver(string $id, int $daysAgo): void
    {
        $this->db->createCommand()->update('{{%outbox_messages}}', [
            'status' => 'DELIVERED',
            'delivered_at' => new Expression("clock_timestamp() - CAST(:days AS integer) * INTERVAL '1 day'", [':days' => $daysAgo]),
            'payload_expires_at' => new Expression("clock_timestamp() - CAST(:offset AS integer) * INTERVAL '1 day'", [':offset' => $daysAgo - 30]),
        ], ['id' => $id])->execute();
    }

    private function store(?Connection $db = null): DbOutboxPayloadCleanupStore
    {
        return new DbOutboxPayloadCleanupStore($db ?? $this->db);
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

    private function intent(string $aggregateId, ?string $keyAggregateId = null): OutboxWriteIntent
    {
        return new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $aggregateId,
            new TelegramUpdateReceivedPayload($aggregateId),
            'payload-cleanup-test:' . ($keyAggregateId ?? $aggregateId),
            $aggregateId,
        );
    }
}
