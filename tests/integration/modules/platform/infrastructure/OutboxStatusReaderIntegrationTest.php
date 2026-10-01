<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use tests\fixtures\platform\TestOutboxRoutes;
use modules\platform\infrastructure\db\DbOutboxStatusReader;
use modules\platform\infrastructure\db\DbOutboxWriter;
use Ramsey\Uuid\Uuid;
use Yii;
use yii\db\Connection;
use yii\db\Expression;

final class OutboxStatusReaderIntegrationTest extends Unit
{
    private Connection $db;
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
        if (isset($this->db)) {
            $this->db->getTransaction()?->rollBack();
            $this->db->createCommand('SET search_path = public')->execute();
            if ($this->ownedIds !== []) {
                $this->db->createCommand()->delete('{{%outbox_messages}}', ['id' => $this->ownedIds])->execute();
            }
        }
    }

    public function testReturnsOnlyReadyRabbitMqCountersWithoutMutation(): void
    {
        $failed = $this->insert();
        $this->change($failed, ['status' => 'FAILED']);
        $expired = $this->insert();
        $this->change($expired, [
            'status' => 'PROCESSING', 'locked_by' => 'synthetic-owner',
            'locked_until' => new Expression("clock_timestamp() - INTERVAL '1 second'"),
        ]);
        $active = $this->insert();
        $this->change($active, [
            'status' => 'PROCESSING', 'locked_by' => 'synthetic-owner',
            'locked_until' => new Expression("clock_timestamp() + INTERVAL '1 day'"),
        ]);
        $this->insert();
        $retryDue = $this->insert();
        $this->change($retryDue, [
            'status' => 'RETRY_SCHEDULED',
            'next_attempt_at' => new Expression("clock_timestamp() - INTERVAL '1 second'"),
        ]);
        $retryFuture = $this->insert();
        $this->change($retryFuture, [
            'status' => 'RETRY_SCHEDULED',
            'next_attempt_at' => new Expression("clock_timestamp() + INTERVAL '1 day'"),
        ]);
        $otherDestination = $this->insert();
        $this->change($otherDestination, ['status' => 'FAILED', 'destination' => 'TELEGRAM']);
        $before = $this->db->createCommand(
            'SELECT id, status, updated_at, payload FROM {{%outbox_messages}} ORDER BY id',
        )->queryAll();

        $result = (new DbOutboxStatusReader($this->db))->getStatus();

        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->expiredLeases);
        self::assertSame(2, $result->due);
        self::assertSame($before, $this->db->createCommand(
            'SELECT id, status, updated_at, payload FROM {{%outbox_messages}} ORDER BY id',
        )->queryAll());
    }

    public function testDatabaseFailureIsNotReportedAsEmptyBacklog(): void
    {
        $this->db->createCommand('SET search_path = pg_catalog')->execute();
        try {
            (new DbOutboxStatusReader($this->db))->getStatus();
            self::fail('Expected persistence failure.');
        } catch (OutboxMaintenanceException $exception) {
            self::assertSame(OutboxMaintenanceError::PERSISTENCE_FAILURE, $exception->error);
            self::assertNull($exception->getPrevious());
        } finally {
            $this->db->createCommand('SET search_path = public')->execute();
        }
    }

    private function insert(): string
    {
        $aggregateId = Uuid::uuid7()->toString();
        $transaction = $this->db->beginTransaction();
        $receipt = (new DbOutboxWriter($this->db, TestOutboxRoutes::registry()))->write(new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $aggregateId,
            new TelegramUpdateReceivedPayload($aggregateId),
            'status-reader-test:' . $aggregateId,
            $aggregateId,
        ));
        $this->ownedIds[] = $receipt->outboxMessageId;
        $transaction->commit();

        return $receipt->outboxMessageId;
    }

    /** @param array<string, mixed> $changes */
    private function change(string $id, array $changes): void
    {
        $this->db->createCommand()->update('{{%outbox_messages}}', $changes, ['id' => $id])->execute();
    }
}
