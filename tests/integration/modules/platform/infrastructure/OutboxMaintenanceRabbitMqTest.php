<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use tests\fixtures\platform\PlatformTestEnvironment;
use Codeception\Test\Unit;
use modules\platform\application\command\RelayOutboxCommand;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\handler\RelayOutboxHandler;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IRetryJitter;
use tests\fixtures\platform\TestOutboxRoutes;
use modules\platform\infrastructure\db\DbOutboxPayloadCleanupStore;
use modules\platform\infrastructure\db\DbOutboxRecoveryStore;
use modules\platform\infrastructure\db\DbOutboxRelayStore;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\platform\infrastructure\db\OutboxRelayRowMapper;
use modules\platform\infrastructure\identity\RamseyOutboxLeaseTokenGenerator;
use modules\platform\infrastructure\rabbitmq\BrokerEnvelopeCodec;
use modules\platform\infrastructure\rabbitmq\RabbitMqPublisher;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionFactory;
use modules\platform\infrastructure\rabbitmq\RabbitMqTopology;
use modules\platform\infrastructure\random\SecureRetryJitter;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;
use tests\integration\modules\platform\infrastructure\rabbitmq\RabbitMqTestEnvironment;
use Yii;
use yii\db\Connection;

final class OutboxMaintenanceRabbitMqTest extends Unit
{
    private Connection $db;
    /** @var list<string> */
    private array $ownedIds = [];

    protected function _before(): void
    {
        self::assertSame('test', getenv('APP_ENV'));
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertSame('ideakit_test', $db->createCommand('SELECT current_database()')->queryScalar());
        self::assertSame(0, (int) $db->createCommand('SELECT count(*) FROM {{%outbox_messages}}')->queryScalar());
        RabbitMqTestEnvironment::factory();
        $this->db = $db;
    }

    protected function _after(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->db->getTransaction()?->rollBack();
        foreach ($this->ownedIds as $id) {
            $effectIds = $this->db->createCommand(
                'SELECT id FROM {{%outbox_messages}} WHERE idempotency_key = :key',
                [':key' => 'worker-test.effect/' . $id],
            )->queryColumn();
            foreach ($effectIds as $effectId) {
                if (!in_array($effectId, $this->ownedIds, true)) {
                    $this->ownedIds[] = $effectId;
                }
            }
        }
        $this->db->createCommand()->delete('{{%outbox_messages}}', ['id' => $this->ownedIds])->execute();
    }

    /** @dataProvider brokerOutcome */
    public function testRecoveryPreservesIntentAndWorkerCommitsOnce(bool $publishedBeforeRecovery): void
    {
        $this->withTopology(function () use ($publishedBeforeRecovery): void {
            $id = $this->writeIntent();
            $settings = self::settings();
            $store = $this->relayStore();
            $claim = $store->claimNext($settings);
            self::assertInstanceOf(OutboxRelayClaim::class, $claim);
            self::assertSame($id, $claim->outboxId);
            self::assertNotNull($claim->envelope);
            if ($publishedBeforeRecovery) {
                self::assertSame($id, $this->publisher()->publish($claim->envelope)->outboxId);
            }
            $this->db->createCommand()->update('{{%outbox_messages}}', [
                'locked_until' => '2000-01-01T00:00:00Z',
            ], ['id' => $id])->execute();

            self::assertSame(1, $this->recovery($settings)->recoverExpired(1, $settings)->retryScheduled);
            $row = $this->row($id);
            self::assertSame('RETRY_SCHEDULED', $row['status']);
            self::assertSame(1, (int) $row['attempt_count']);
            $history = json_decode($row['attempt_history'], true, 16, JSON_THROW_ON_ERROR);
            self::assertSame('lease_expired', $history['attempts'][0]['error_code']);
            self::assertSame('RETRY_SCHEDULED', $history['attempts'][0]['outcome']);
            self::assertSame(0, $this->recovery($settings)->recoverExpired(1, $settings)->retryScheduled);
            $this->db->createCommand()->update('{{%outbox_messages}}', [
                'next_attempt_at' => '2000-01-01T00:00:00Z',
            ], ['id' => $id])->execute();

            $receipt = (new RelayOutboxHandler(
                $store,
                $this->publisher(),
                $settings,
                new OutboxRetryPolicy($settings, new SecureRetryJitter()),
            ))->handle(new RelayOutboxCommand(1));
            self::assertSame(1, $receipt->delivered);
            $row = $this->row($id);
            self::assertSame('DELIVERED', $row['status']);
            self::assertSame(2, (int) $row['attempt_count']);
            self::assertCount(2, json_decode($row['attempt_history'], true, 16, JSON_THROW_ON_ERROR)['attempts']);

            $worker = $this->worker($publishedBeforeRecovery ? 2 : 1);
            $worker->run();
            self::assertSame(0, $worker->getExitCode());
            self::assertStringContainsString(
                $publishedBeforeRecovery ? 'completed=1 already_completed=1' : 'completed=1 already_completed=0',
                $worker->getOutput(),
            );
            self::assertSame(1, (int) $this->db->createCommand(
                'SELECT count(*) FROM {{%outbox_messages}} WHERE idempotency_key = :key',
                [':key' => 'worker-test.effect/' . $id],
            )->queryScalar());
            $this->assertQueueCount('critical', 0);

            $this->db->createCommand(
                "UPDATE {{%outbox_messages}} SET delivered_at = clock_timestamp() - INTERVAL '31 days', "
                . "payload_expires_at = clock_timestamp() - INTERVAL '1 day' WHERE id = :id",
                [':id' => $id],
            )->execute();
            self::assertSame(1, (new DbOutboxPayloadCleanupStore($this->db))->clearDue(1)->cleared);
            $cleaned = $this->row($id);
            self::assertSame('DELIVERED', $cleaned['status']);
            self::assertNull($cleaned['payload']);
            self::assertSame($row['payload_hash'], $cleaned['payload_hash']);
            self::assertSame($row['attempt_history'], $cleaned['attempt_history']);
        });
    }

    public function testFailedOutboxAndDeadLetterSurviveMaintenance(): void
    {
        $this->withTopology(function (): void {
            $id = $this->writeIntent();
            $settings = new OutboxRelaySettings(1, 600, 15, 900);
            $claim = $this->relayStore()->claimNext($settings);
            self::assertInstanceOf(OutboxRelayClaim::class, $claim);
            self::assertNotNull($claim->envelope);
            $this->db->createCommand()->update('{{%outbox_messages}}', [
                'locked_until' => '2000-01-01T00:00:00Z',
            ], ['id' => $id])->execute();
            $unavailable = new RabbitMqPublisher(
                new RabbitMqConnectionFactory(new RabbitMqConnectionConfig(
                    '127.0.0.1',
                    1,
                    'synthetic',
                    'synthetic',
                    'ideakit_transport_test',
                    0.1,
                    1.0,
                    1,
                    3.0,
                    3.0,
                    1.0,
                    1.0,
                )),
                new BrokerEnvelopeCodec(TestOutboxRoutes::registry()),
                1.0,
                TestOutboxRoutes::registry(),
            );
            try {
                $unavailable->publish($claim->envelope);
                self::fail('Expected unavailable broker endpoint.');
            } catch (BrokerTransportException $exception) {
                self::assertNull($exception->getPrevious());
            }
            self::assertSame(1, $this->recovery($settings)->recoverExpired(1, $settings)->failed);
            $before = $this->row($id);
            self::assertSame('FAILED', $before['status']);
            self::assertNotNull($before['payload']);

            $deadLetterId = $this->writeIntent();
            $relay = new RelayOutboxHandler(
                $this->relayStore(),
                $this->publisher(),
                $settings,
                new OutboxRetryPolicy($settings, new SecureRetryJitter()),
            );
            self::assertSame(1, $relay->handle(new RelayOutboxCommand(1))->delivered);
            self::assertSame('DELIVERED', $this->row($deadLetterId)['status']);
            $worker = $this->worker(1, 'terminal');
            $worker->run();
            self::assertSame(0, $worker->getExitCode());
            self::assertStringContainsString('rejected=1', $worker->getOutput());
            $this->assertQueueCount('critical.failed', 1);

            self::assertSame(0, $this->recovery($settings)->recoverExpired(1, $settings)->failed);
            self::assertSame(0, (new DbOutboxPayloadCleanupStore($this->db))->clearDue(1)->cleared);
            self::assertSame($before, $this->row($id));
            $this->assertQueueCount('critical.failed', 1);
        });
    }

    /** @return iterable<string, array{bool}> */
    public static function brokerOutcome(): iterable
    {
        yield 'relay stopped before publication' => [false];
        yield 'broker accepted before relay stopped' => [true];
    }

    private function writeIntent(): string
    {
        $updateId = Uuid::uuid7()->toString();
        $transaction = $this->db->beginTransaction();
        $id = (new DbOutboxWriter($this->db, TestOutboxRoutes::registry()))->write(new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $updateId,
            new TelegramUpdateReceivedPayload($updateId),
            'maintenance-test.original/' . $updateId,
            $updateId,
        ))->outboxMessageId;
        $transaction->commit();
        $this->ownedIds[] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        $row = $this->db->createCommand('SELECT * FROM {{%outbox_messages}} WHERE id = :id', [':id' => $id])->queryOne();
        self::assertIsArray($row);

        return $row;
    }

    private function relayStore(): DbOutboxRelayStore
    {
        return new DbOutboxRelayStore(
            $this->db,
            new OutboxRelayRowMapper(TestOutboxRoutes::registry()),
            new RamseyOutboxLeaseTokenGenerator(),
        );
    }

    private function recovery(OutboxRelaySettings $settings): DbOutboxRecoveryStore
    {
        $jitter = new class () implements IRetryJitter {
            public function between(int $minimum, int $maximum): int
            {
                return $minimum;
            }
        };

        return new DbOutboxRecoveryStore(
            $this->db,
            new OutboxRelayRowMapper(TestOutboxRoutes::registry()),
            new OutboxRetryPolicy($settings, $jitter),
        );
    }

    private function publisher(): RabbitMqPublisher
    {
        return new RabbitMqPublisher(RabbitMqTestEnvironment::factory(), new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), 5.0, TestOutboxRoutes::registry());
    }

    private function worker(int $limit, string $scenario = 'normal'): Process
    {
        return new Process([
            PHP_BINARY, 'tests/bin/critical-worker.php', 'platform-worker/critical',
            '--limit=' . $limit, '--maxRuntime=20',
        ], dirname(__DIR__, 5), array_merge(PlatformTestEnvironment::workerEnvironment(), [
            'WORKER_TEST_SCENARIO' => $scenario,
        ]), null, 30.0);
    }

    private function assertQueueCount(string $queue, int $expected): void
    {
        $connection = RabbitMqTestEnvironment::factory()->connect();
        try {
            [, $count] = $connection->channel()->queue_declare($queue, true);
            self::assertSame($expected, $count);
        } finally {
            $connection->close();
        }
    }

    /** @param callable(): void $test */
    private function withTopology(callable $test): void
    {
        $lock = fopen(sys_get_temp_dir() . '/ideakit-broker-integration.lock', 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $connection = null;
        $ownsTopology = false;
        try {
            $factory = RabbitMqTestEnvironment::factory();
            $connection = $factory->connect();
            self::requireAbsentTopology($connection);
            $ownsTopology = true;
            (new RabbitMqTopology($factory))->declare();
            $test();
        } finally {
            try {
                if ($connection !== null && $ownsTopology) {
                    $channel = $connection->channel();
                    $channel->queue_delete('critical');
                    $channel->queue_delete('critical.failed');
                    $channel->exchange_delete('ideakit.commands');
                    $channel->exchange_delete('ideakit.dead-letter');
                }
            } finally {
                $connection?->close();
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function requireAbsentTopology(AbstractConnection $connection): void
    {
        foreach (['critical', 'critical.failed', 'ideakit.commands', 'ideakit.dead-letter'] as $name) {
            $channel = $connection->channel();
            try {
                if (str_starts_with($name, 'critical')) {
                    $channel->queue_declare($name, true);
                } else {
                    $channel->exchange_declare($name, 'direct', true);
                }
                self::fail('Topology fixture requires absent test resources.');
            } catch (AMQPProtocolChannelException $exception) {
                self::assertSame(404, $exception->getCode());
            }
        }
    }

    private static function settings(): OutboxRelaySettings
    {
        return new OutboxRelaySettings(5, 600, 15, 900);
    }
}
