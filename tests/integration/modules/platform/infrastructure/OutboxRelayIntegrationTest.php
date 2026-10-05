<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\application\command\RelayOutboxCommand;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\BrokerPublishReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\handler\RelayOutboxHandler;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IBrokerPublisher;
use modules\platform\application\port\IOutboxRelayStore;
use tests\fixtures\platform\TestOutboxRoutes;
use modules\platform\infrastructure\db\DbOutboxRelayStore;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\platform\infrastructure\db\OutboxRelayRowMapper;
use modules\platform\infrastructure\identity\RamseyOutboxLeaseTokenGenerator;
use modules\platform\infrastructure\random\SecureRetryJitter;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Yii;
use yii\db\Connection;

final class OutboxRelayIntegrationTest extends Unit
{
    private Connection $db;
    private Connection $observer;
    /** @var list<string> */
    private array $ids = [];

    protected function _before(): void
    {
        self::assertSame('test', getenv('APP_ENV'));
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertSame('ideakit_test', $db->createCommand('SELECT current_database()')->queryScalar());
        self::assertSame(0, (int) $db->createCommand('SELECT count(*) FROM {{%outbox_messages}}')->queryScalar());
        $this->db = $db;
        $this->observer = new Connection(['dsn' => $db->dsn, 'username' => $db->username, 'password' => $db->password]);
    }

    protected function _after(): void
    {
        if (isset($this->db)) {
            $this->db->getTransaction()?->rollBack();
            if ($this->ids !== []) {
                $this->db->createCommand()->delete('{{%outbox_messages}}', ['id' => $this->ids])->execute();
            }
        }
        if (isset($this->observer)) {
            $this->observer->close();
        }
    }

    public function testWriterCommitPrecedesPublicationAndCallerRollbackPreservesAbsence(): void
    {
        $transaction = $this->db->beginTransaction();
        $rolledBack = $this->write();
        self::assertFalse($this->row($rolledBack, $this->observer));
        $transaction->rollBack();
        self::assertFalse($this->row($rolledBack));
        $transaction = $this->db->beginTransaction();
        $id = $this->write();
        self::assertFalse($this->row($id, $this->observer));
        $transaction->commit();
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')->willReturnCallback(function (BrokerEnvelope $envelope) use ($id): BrokerPublishReceipt {
            self::assertSame($id, $envelope->outboxId);
            self::assertNull($this->db->getTransaction());
            self::assertFalse($this->db->pdo->inTransaction());
            $row = $this->row($id, $this->observer);
            self::assertIsArray($row);
            self::assertSame('PROCESSING', $row['status']);
            self::assertSame(1, (int) $row['attempt_count']);
            return new BrokerPublishReceipt($id);
        });
        $receipt = $this->handler($publisher)->handle(new RelayOutboxCommand(10));
        self::assertSame(1, $receipt->delivered);
        self::assertSame('DELIVERED', $this->row($id)['status']);
    }

    public function testUnexpectedPublisherFailurePreservesLeaseAndStopsFurtherClaims(): void
    {
        $transaction = $this->db->beginTransaction();
        $first = $this->write();
        $second = $this->write();
        $transaction->commit();
        $failure = new RuntimeException('synthetic-sensitive-publisher');
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')->willReturnCallback(function (BrokerEnvelope $envelope) use ($first, $failure): never {
            self::assertSame($first, $envelope->outboxId);
            self::assertNull($this->db->getTransaction());
            self::assertFalse($this->db->pdo->inTransaction());
            self::assertSame('PROCESSING', $this->row($first, $this->observer)['status']);
            throw $failure;
        });

        try {
            $this->handler($publisher)->handle(new RelayOutboxCommand(10));
            self::fail('Expected unexpected publisher failure.');
        } catch (OutboxRelayException $exception) {
            self::assertSame(OutboxRelayError::UNEXPECTED_FAILURE, $exception->error);
            self::assertSame(SafeCauseCode::UNKNOWN, $exception->causeCode);
            self::assertSame('unexpected_failure', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
        $row = $this->row($first, $this->observer);
        self::assertIsArray($row);
        self::assertSame('PROCESSING', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertNotNull($row['locked_by']);
        self::assertNotNull($row['locked_until']);
        self::assertNull($row['delivered_at']);
        self::assertNotNull($row['payload']);
        self::assertSame('PENDING', $this->row($second)['status']);
        self::assertSame(0, (int) $this->row($second)['attempt_count']);
    }

    /** @dataProvider failures */
    public function testTransportFailureIsPersisted(BrokerTransportErrorCode $error, int $maxAttempts, string $status): void
    {
        $transaction = $this->db->beginTransaction();
        $id = $this->write();
        $transaction->commit();
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')->willThrowException(new BrokerTransportException($error));
        $receipt = $this->handler($publisher, null, $maxAttempts)->handle(new RelayOutboxCommand(1));
        $row = $this->row($id);
        self::assertIsArray($row);
        self::assertSame($status, $row['status']);
        self::assertSame($error->value, $row['last_error_code']);
        self::assertSame(1, $receipt->retryScheduled + $receipt->failed);
    }

    public function testAmbiguousConfirmRetryPreservesOutboxId(): void
    {
        $transaction = $this->db->beginTransaction();
        $id = $this->write();
        $transaction->commit();
        $attempts = [];
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(static function (BrokerEnvelope $envelope) use (&$attempts): BrokerPublishReceipt {
            $attempts[] = $envelope;
            if (count($attempts) === 1) {
                throw new BrokerTransportException(BrokerTransportErrorCode::CONFIRM_TIMEOUT);
            }
            return new BrokerPublishReceipt($envelope->outboxId);
        });
        $handler = $this->handler($publisher);
        self::assertSame(1, $handler->handle(new RelayOutboxCommand(1))->retryScheduled);
        $this->db->createCommand("UPDATE {{%outbox_messages}} SET next_attempt_at = clock_timestamp() WHERE id = :id", [':id' => $id])->execute();
        self::assertSame(1, $handler->handle(new RelayOutboxCommand(1))->delivered);
        self::assertEquals($attempts[0], $attempts[1]);
        self::assertSame($id, $attempts[1]->outboxId);
        self::assertSame(2, (int) $this->row($id)['attempt_count']);
    }

    public function testFinalizationFailureStopsFurtherClaimsWithoutLosingIntent(): void
    {
        $transaction = $this->db->beginTransaction();
        $first = $this->write();
        $second = $this->write();
        $transaction->commit();
        $real = $this->store();
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturnCallback($real->claimNext(...));
        $store->method('isLeaseActive')->willReturnCallback($real->isLeaseActive(...));
        $store->expects(self::once())->method('finish')->willThrowException(new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE));
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')->willReturnCallback(static fn (BrokerEnvelope $envelope): BrokerPublishReceipt => new BrokerPublishReceipt($envelope->outboxId));
        try {
            $this->handler($publisher, $store)->handle(new RelayOutboxCommand(10));
            self::fail('Expected finalization failure.');
        } catch (OutboxRelayException $exception) {
            self::assertSame(OutboxRelayError::PERSISTENCE_FAILURE, $exception->error);
            self::assertNull($exception->getPrevious());
        }
        self::assertSame('PROCESSING', $this->row($first)['status']);
        self::assertSame('PENDING', $this->row($second)['status']);
    }

    /** @return iterable<string, array{BrokerTransportErrorCode, int, string}> */
    public static function failures(): iterable
    {
        yield 'transient' => [BrokerTransportErrorCode::CONNECTION_FAILURE, 5, 'RETRY_SCHEDULED'];
        yield 'terminal' => [BrokerTransportErrorCode::UNROUTABLE, 5, 'FAILED'];
        yield 'exhausted' => [BrokerTransportErrorCode::CONFIRM_TIMEOUT, 1, 'FAILED'];
    }

    private function write(): string
    {
        $update = Uuid::uuid7()->toString();
        $receipt = (new DbOutboxWriter($this->db, TestOutboxRoutes::registry()))->write(new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $update,
            new TelegramUpdateReceivedPayload($update),
            'relay-integration-' . $update,
            Uuid::uuid7()->toString(),
        ));
        $this->ids[] = $receipt->outboxMessageId;
        return $receipt->outboxMessageId;
    }

    private function store(): DbOutboxRelayStore
    {
        return new DbOutboxRelayStore($this->db, new OutboxRelayRowMapper(TestOutboxRoutes::registry()), new RamseyOutboxLeaseTokenGenerator());
    }

    private function handler(IBrokerPublisher $publisher, ?IOutboxRelayStore $store = null, int $maxAttempts = 5): RelayOutboxHandler
    {
        $settings = new OutboxRelaySettings($maxAttempts, 600, 15, 900);
        return new RelayOutboxHandler($store ?? $this->store(), $publisher, $settings, new OutboxRetryPolicy($settings, new SecureRetryJitter()));
    }

    /** @return array<string, mixed>|false */
    private function row(string $id, ?Connection $db = null): array|false
    {
        return ($db ?? $this->db)->createCommand('SELECT * FROM {{%outbox_messages}} WHERE id = :id', [':id' => $id])->queryOne();
    }
}
