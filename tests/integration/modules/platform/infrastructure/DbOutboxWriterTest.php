<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\platform\application\exception\OutboxWriteException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use tests\fixtures\platform\TestOutboxRoutes;
use modules\platform\infrastructure\db\DbOutboxWriter;
use Yii;
use yii\db\Connection;
use yii\db\Transaction;

final class DbOutboxWriterTest extends Unit
{
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789bd1';
    private const OTHER_UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789bd2';
    private const CORRELATION_ID = '01890f4d-3c2a-7f48-8c0b-123456789bd3';

    private Connection $db;
    private DbOutboxWriter $writer;
    private ?Transaction $transaction = null;

    protected function _before(): void
    {
        self::assertTrue(YII_ENV_TEST);
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertSame('ideakit_test', $db->createCommand('SELECT current_database()')->queryScalar());
        $this->db = $db;
        $this->writer = new DbOutboxWriter($db, TestOutboxRoutes::registry());
    }

    protected function _after(): void
    {
        if ($this->transaction !== null && $this->transaction->getIsActive()) {
            $this->transaction->rollBack();
        }
    }

    public function testRejectsWriteWithoutCallerTransaction(): void
    {
        try {
            $this->writer->write(self::intent());
            self::fail('Expected a transaction error.');
        } catch (OutboxWriteException $exception) {
            self::assertSame(OutboxWriteFailure::TRANSACTION_REQUIRED, $exception->failure);
        }

        self::assertSame(0, (int) $this->db->createCommand(
            'SELECT count(*) FROM {{%outbox_messages}} WHERE idempotency_key = :key',
            [':key' => 'platform-writer-test'],
        )->queryScalar());
    }

    public function testPersistsApprovedPendingMessageInCallerTransaction(): void
    {
        $this->transaction = $this->db->beginTransaction();
        $receipt = $this->writer->write(self::intent());

        self::assertSame(OutboxWriteOutcome::CREATED, $receipt->outcome);
        self::assertSame(7, \Ramsey\Uuid\Uuid::fromString($receipt->outboxMessageId)->getVersion());
        $row = $this->db->createCommand(
            'SELECT * FROM {{%outbox_messages}} WHERE id = :id',
            [':id' => $receipt->outboxMessageId],
        )->queryOne();
        self::assertIsArray($row);
        self::assertSame('Telegram', $row['owner_module']);
        self::assertSame('RABBITMQ', $row['destination']);
        self::assertSame('critical', $row['routing_key']);
        self::assertSame('telegram.update.received', $row['message_type']);
        self::assertSame('1.0', $row['schema_version']);
        self::assertSame('TELEGRAM_UPDATE', $row['aggregate_type']);
        self::assertSame(self::UPDATE_ID, $row['aggregate_id']);
        self::assertSame(['update_id' => self::UPDATE_ID], json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('e566f922aed6c1b103584da924a9845ea84565b58a886c4c565103ee58fa0449', $row['payload_hash']);
        self::assertSame('PENDING', $row['status']);
        self::assertSame(0, (int) $row['attempt_count']);
        self::assertEqualsCanonicalizing(['schema_version' => '1.0', 'attempts' => []], json_decode((string) $row['attempt_history'], true, 512, JSON_THROW_ON_ERROR));
        foreach (['recipient_user_id', 'telegram_identity_profile_id', 'chat_id', 'idea_publication_id', 'idea_version_id', 'next_attempt_at', 'locked_by', 'locked_until', 'external_reference', 'last_error_code', 'delivered_at', 'payload_expires_at'] as $column) {
            self::assertNull($row[$column], $column);
        }
    }

    public function testSameEffectReturnsExistingMessageWithoutChangingDeliveryState(): void
    {
        $this->transaction = $this->db->beginTransaction();
        $created = $this->writer->write(self::intent());
        $this->db->createCommand()->update('{{%outbox_messages}}', [
            'status' => 'DELIVERED',
            'attempt_count' => 2,
            'payload' => null,
            'delivered_at' => '2026-09-26T09:05:00+00:00',
        ], ['id' => $created->outboxMessageId])->execute();
        $before = $this->db->createCommand(
            'SELECT status, attempt_count, correlation_id, created_at, updated_at, payload FROM {{%outbox_messages}} WHERE id = :id',
            [':id' => $created->outboxMessageId],
        )->queryOne();

        $repeated = $this->writer->write(new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            self::UPDATE_ID,
            new TelegramUpdateReceivedPayload(self::UPDATE_ID),
            'platform-writer-test',
            self::OTHER_UPDATE_ID,
        ));

        self::assertSame(OutboxWriteOutcome::ALREADY_EXISTS, $repeated->outcome);
        self::assertSame($created->outboxMessageId, $repeated->outboxMessageId);
        self::assertSame($before, $this->db->createCommand(
            'SELECT status, attempt_count, correlation_id, created_at, updated_at, payload FROM {{%outbox_messages}} WHERE id = :id',
            [':id' => $created->outboxMessageId],
        )->queryOne());
        self::assertSame(1, (int) $this->db->createCommand(
            'SELECT count(*) FROM {{%outbox_messages}} WHERE idempotency_key = :key',
            [':key' => 'platform-writer-test'],
        )->queryScalar());
    }

    public function testDifferentEffectWithSameKeyIsRejectedWithoutChangingRow(): void
    {
        $this->transaction = $this->db->beginTransaction();
        $created = $this->writer->write(self::intent());

        try {
            $this->writer->write(self::intent(self::OTHER_UPDATE_ID));
            self::fail('Expected an idempotency conflict.');
        } catch (OutboxWriteException $exception) {
            self::assertSame(OutboxWriteFailure::IDEMPOTENCY_CONFLICT, $exception->failure);
        }
        self::assertSame($created->outboxMessageId, $this->db->createCommand(
            'SELECT id FROM {{%outbox_messages}} WHERE idempotency_key = :key',
            [':key' => 'platform-writer-test'],
        )->queryScalar());
    }

    public function testDifferentImmutableRouteMetadataIsAnIdempotencyConflict(): void
    {
        $this->transaction = $this->db->beginTransaction();
        $created = $this->writer->write(self::intent());
        $this->db->createCommand()->update(
            '{{%outbox_messages}}',
            ['routing_key' => 'different'],
            ['id' => $created->outboxMessageId],
        )->execute();

        try {
            $this->writer->write(self::intent());
            self::fail('Expected an idempotency conflict.');
        } catch (OutboxWriteException $exception) {
            self::assertSame(OutboxWriteFailure::IDEMPOTENCY_CONFLICT, $exception->failure);
            self::assertSame('idempotency_conflict', $exception->getMessage());
        }
    }

    public function testDatabaseErrorIsTranslatedWithoutLeakingSqlOrIdentifiers(): void
    {
        $this->transaction = $this->db->beginTransaction();
        $this->db->createCommand("SET LOCAL search_path = pg_catalog")->execute();

        try {
            $this->writer->write(self::intent());
            self::fail('Expected a persistence error.');
        } catch (OutboxWriteException $exception) {
            self::assertSame(OutboxWriteFailure::PERSISTENCE_FAILURE, $exception->failure);
            self::assertSame('persistence_failure', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testCallerCommitAndRollbackControlBothRows(): void
    {
        self::assertSame(0, (int) $this->db->createCommand(
            'SELECT count(*) FROM {{%telegram_updates}} WHERE id = :id',
            [':id' => self::UPDATE_ID],
        )->queryScalar());
        self::assertSame(0, (int) $this->db->createCommand(
            'SELECT count(*) FROM {{%outbox_messages}} WHERE idempotency_key = :key',
            [':key' => 'platform-writer-test'],
        )->queryScalar());

        $this->transaction = $this->db->beginTransaction();
        $this->insertCallerState();
        $this->writer->write(self::intent());
        $this->transaction->rollBack();
        self::assertSame([0, 0], $this->rowCounts());

        $this->transaction = $this->db->beginTransaction();
        try {
            $this->insertCallerState();
            $this->writer->write(self::intent());
            $this->transaction->commit();
            self::assertSame([1, 1], $this->rowCounts());
        } finally {
            if ($this->transaction->getIsActive()) {
                $this->transaction->rollBack();
            }
            $cleanup = $this->db->beginTransaction();
            try {
                $this->db->createCommand()->delete('{{%outbox_messages}}', ['idempotency_key' => 'platform-writer-test'])->execute();
                $this->db->createCommand()->delete('{{%telegram_updates}}', ['id' => self::UPDATE_ID])->execute();
                $cleanup->commit();
            } finally {
                if ($cleanup->getIsActive()) {
                    $cleanup->rollBack();
                }
            }
        }
        self::assertSame([0, 0], $this->rowCounts());
    }

    /** @dataProvider concurrentOutcomes */
    public function testConcurrentWriterUsesUniqueKeyAfterFirstTransactionResolves(
        bool $commitFirst,
        string $secondAggregateId,
        string $expectedOutcome,
    ): void {
        self::assertSame(0, (int) $this->db->createCommand(
            'SELECT count(*) FROM {{%outbox_messages}} WHERE idempotency_key = :key',
            [':key' => 'platform-writer-test'],
        )->queryScalar());
        $this->transaction = $this->db->beginTransaction();
        $first = $this->writer->write(self::intent());
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', self::CHILD_WRITER, $secondAggregateId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 5),
        );
        self::assertIsResource($process);
        try {
            fclose($pipes[0]);
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            self::assertSame(1, stream_select($read, $write, $except, 5));
            self::assertSame("READY\n", fgets($pipes[1]));

            $deadline = microtime(true) + 5;
            $waiting = false;
            do {
                $waiting = (int) $this->db->createCommand(
                    "SELECT count(*) FROM pg_stat_activity WHERE application_name = 'platform-outbox-concurrent-test' AND wait_event_type = 'Lock'",
                )->queryScalar() === 1;
            } while (!$waiting && microtime(true) < $deadline);
            self::assertTrue($waiting, 'The second PostgreSQL session must be blocked by the first transaction.');

            if ($commitFirst) {
                $this->transaction->commit();
            } else {
                $this->transaction->rollBack();
            }
            stream_set_timeout($pipes[1], 10);
            $result = trim(stream_get_contents($pipes[1]));
            self::assertSame(
                $expectedOutcome === 'ALREADY_EXISTS' ? 'ALREADY_EXISTS:' . $first->outboxMessageId : $expectedOutcome,
                $result,
            );
            self::assertSame(0, proc_close($process));
            $process = null;
        } finally {
            if ($this->transaction->getIsActive()) {
                $this->transaction->rollBack();
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
            if ($commitFirst) {
                $this->db->createCommand()->delete('{{%outbox_messages}}', ['id' => $first->outboxMessageId])->execute();
            }
        }
    }

    /** @return iterable<string, array{bool, string, string}> */
    public static function concurrentOutcomes(): iterable
    {
        yield 'committed duplicate' => [true, self::UPDATE_ID, 'ALREADY_EXISTS'];
        yield 'rolled back winner' => [false, self::UPDATE_ID, 'CREATED'];
        yield 'committed conflicting effect' => [true, self::OTHER_UPDATE_ID, 'idempotency_conflict'];
    }

    private const CHILD_WRITER = <<<'PHP'
require getcwd() . '/vendor/yiisoft/yii2/Yii.php';
require getcwd() . '/vendor/autoload.php';
if (getenv('APP_ENV') !== 'test') { exit(2); }
$db = new yii\db\Connection([
    'dsn' => getenv('TEST_DB_DSN'),
    'username' => getenv('TEST_DB_USERNAME'),
    'password' => getenv('TEST_DB_PASSWORD'),
]);
if ($db->createCommand('SELECT current_database()')->queryScalar() !== 'ideakit_test') { exit(3); }
$db->createCommand("SET application_name = 'platform-outbox-concurrent-test'")->execute();
$db->createCommand('SET statement_timeout = 10000')->execute();
$transaction = $db->beginTransaction();
echo "READY\n";
flush();
try {
    $intent = new modules\platform\application\dto\OutboxWriteIntent(
        'Telegram', 'telegram.update.received', '1.0', 'TELEGRAM_UPDATE', $argv[1],
        new modules\telegram\application\message\TelegramUpdateReceivedPayload($argv[1]),
        'platform-writer-test', '01890f4d-3c2a-7f48-8c0b-123456789bd4',
    );
    $writer = new modules\platform\infrastructure\db\DbOutboxWriter(
        $db, tests\fixtures\platform\TestOutboxRoutes::registry(),
    );
    $receipt = $writer->write($intent);
    echo $receipt->outcome->value;
    if ($receipt->outcome->value === 'ALREADY_EXISTS') { echo ':' . $receipt->outboxMessageId; }
} catch (modules\platform\application\exception\OutboxWriteException $exception) {
    echo $exception->failure->value;
}
$transaction->rollBack();
PHP;

    /** @return array{int, int} */
    private function rowCounts(): array
    {
        return [
            (int) $this->db->createCommand('SELECT count(*) FROM {{%telegram_updates}} WHERE id = :id', [':id' => self::UPDATE_ID])->queryScalar(),
            (int) $this->db->createCommand('SELECT count(*) FROM {{%outbox_messages}} WHERE idempotency_key = :key', [':key' => 'platform-writer-test'])->queryScalar(),
        ];
    }

    private function insertCallerState(): void
    {
        $this->db->createCommand()->insert('{{%telegram_updates}}', [
            'id' => self::UPDATE_ID,
            'bot_key' => 'platform-test',
            'update_id' => 1000000000000000081,
            'update_type' => 'MESSAGE',
            'payload_schema_version' => 'telegram.update/1.0',
            'payload_hash' => str_repeat('a', 64),
            'raw_payload' => new \yii\db\JsonExpression(['update_id' => 1000000000000000081], 'jsonb'),
            'status' => 'RECEIVED',
            'received_at' => '2026-09-26T09:00:00+00:00',
            'raw_payload_expires_at' => '2026-09-27T09:00:00+00:00',
        ])->execute();
    }

    private static function intent(string $aggregateId = self::UPDATE_ID): OutboxWriteIntent
    {
        return new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $aggregateId,
            new TelegramUpdateReceivedPayload($aggregateId),
            'platform-writer-test',
            self::CORRELATION_ID,
        );
    }
}
