<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use tests\fixtures\platform\PlatformTestEnvironment;
use Codeception\Test\Unit;
use modules\platform\application\command\RelayOutboxCommand;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\handler\RelayOutboxHandler;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\policy\OutboxRetryPolicy;
use tests\fixtures\platform\TestOutboxRoutes;
use modules\platform\infrastructure\db\DbOutboxRelayStore;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\platform\infrastructure\db\OutboxRelayRowMapper;
use modules\platform\infrastructure\identity\RamseyOutboxLeaseTokenGenerator;
use modules\platform\infrastructure\rabbitmq\BrokerEnvelopeCodec;
use modules\platform\infrastructure\rabbitmq\RabbitMqPublisher;
use modules\platform\infrastructure\rabbitmq\RabbitMqTopology;
use modules\platform\infrastructure\random\SecureRetryJitter;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use tests\integration\modules\platform\infrastructure\rabbitmq\RabbitMqTestEnvironment;
use Yii;
use yii\db\Connection;

final class CriticalWorkerRabbitMqTest extends Unit
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
        RabbitMqTestEnvironment::factory();
        $this->db = $db;
    }

    protected function _after(): void
    {
        if (isset($this->db)) {
            $this->db->getTransaction()?->rollBack();
            foreach ($this->ownedIds as $originalId) {
                $rows = $this->db->createCommand(
                    'SELECT id FROM {{%outbox_messages}} WHERE idempotency_key = :key',
                    [':key' => 'worker-test.effect/' . $originalId],
                )->queryColumn();
                foreach ($rows as $id) {
                    if (!in_array($id, $this->ownedIds, true)) {
                        $this->ownedIds[] = $id;
                    }
                }
            }
            foreach (array_reverse($this->ownedIds) as $id) {
                $this->db->createCommand()->delete('{{%outbox_messages}}', ['id' => $id])->execute();
            }
        }
    }

    public function testWriterRelayWorkerCommitAndAck(): void
    {
        $this->withTopology(function (): void {
            $message = $this->originalFromWriterAndRelay();
            $process = $this->runWorker('normal');
            self::assertSame(0, $process->getExitCode());
            self::assertStringContainsString('completed=1 already_completed=0 rejected=0', $process->getOutput());
            self::assertSame('DELIVERED', $this->db->createCommand('SELECT status FROM {{%outbox_messages}} WHERE id = :id', [':id' => $message->outboxId])->queryScalar());
            self::assertSame(1, $this->effectCount($message->outboxId));
            $this->assertQueueCount('critical', 0);
        });
    }

    public function testCrashAfterCommitRedeliversWithoutSecondEffect(): void
    {
        $this->withTopology(function (): void {
            $message = $this->originalFromWriterAndRelay();
            $process = $this->worker('crash_after_commit');
            try {
                $process->start();
                self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "COMMITTED\n")));
                self::assertSame(1, $this->effectCount($message->outboxId));
                $process->signal(SIGKILL);
                self::awaitExit($process);
                self::assertNotSame(0, $process->getExitCode());
            } finally {
                $process->stop(0.0, SIGKILL);
            }
            $retry = $this->runWorker('normal');
            self::assertSame(0, $retry->getExitCode());
            self::assertStringContainsString('already_completed=1', $retry->getOutput());
            self::assertSame(1, $this->effectCount($message->outboxId));
            $this->assertQueueCount('critical', 0);
        });
    }

    public function testDuplicatePublicationHasOneDurableEffect(): void
    {
        $this->withTopology(function (): void {
            $message = $this->originalFromWriterAndRelay();
            (new RabbitMqPublisher(RabbitMqTestEnvironment::factory(), new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), 5.0, TestOutboxRoutes::registry()))->publish($message);
            $process = $this->runWorker('normal', 2);
            self::assertSame(0, $process->getExitCode());
            self::assertStringContainsString('completed=1 already_completed=1', $process->getOutput());
            self::assertSame(1, $this->effectCount($message->outboxId));
            $this->assertQueueCount('critical', 0);
        });
    }

    public function testTerminalRefusalIsDeadLettered(): void
    {
        $this->withTopology(function (): void {
            $message = $this->originalFromWriterAndRelay();
            $process = $this->runWorker('terminal');
            self::assertSame(0, $process->getExitCode());
            self::assertStringContainsString('rejected=1', $process->getOutput());
            self::assertSame(0, $this->effectCount($message->outboxId));
            $this->assertQueueCount('critical.failed', 1);
        });
    }

    /** @dataProvider invalidMessages */
    public function testUnknownOrMalformedMessageDoesNotDispatch(bool $malformed): void
    {
        $this->withTopology(function () use ($malformed): void {
            $message = $this->originalFromWriterAndRelay();
            $connection = RabbitMqTestEnvironment::factory()->connect();
            try {
                $channel = $connection->channel();
                if ($malformed) {
                    $channel->basic_publish(new AMQPMessage('{', ['delivery_mode' => 2]), 'ideakit.commands', 'critical', true);
                } else {
                    $unknownId = Uuid::uuid7()->toString();
                    $unknown = new AMQPMessage(json_encode([
                        'outbox_id' => $unknownId,
                        'message_type' => 'future.command',
                        'schema_version' => '2.0',
                        'correlation_id' => $message->correlationId,
                        'payload' => ['future_id' => 'synthetic'],
                    ], JSON_THROW_ON_ERROR), [
                        'message_id' => $unknownId,
                        'correlation_id' => $message->correlationId,
                        'content_type' => 'application/json',
                        'delivery_mode' => 2,
                    ]);
                    $channel->basic_publish($unknown, 'ideakit.commands', 'critical', true);
                }
            } finally {
                $connection->close();
            }
            $process = $this->runWorker('normal', 2);
            self::assertSame(0, $process->getExitCode());
            self::assertStringContainsString('rejected=1', $process->getOutput());
            self::assertSame(1, $this->effectCount($message->outboxId));
            $this->assertQueueCount('critical.failed', 1);
        });
    }

    public function testUnexpectedFailureLeavesMessageForAnotherRun(): void
    {
        $this->withTopology(function (): void {
            $message = $this->originalFromWriterAndRelay();
            $failure = $this->runWorker('unexpected');
            self::assertSame(1, $failure->getExitCode());
            self::assertTrue(str_contains($failure->getErrorOutput(), 'handler_failure'), 'unexpected_child_stderr');
            self::assertSame(0, $this->effectCount($message->outboxId));
            $retry = $this->runWorker('normal');
            self::assertSame(0, $retry->getExitCode());
            self::assertSame(1, $this->effectCount($message->outboxId));
        });
    }

    public function testStopAfterCurrentCommitDoesNotDispatchNext(): void
    {
        $this->withTopology(function (): void {
            $first = $this->originalFromWriterAndRelay();
            $second = $this->originalFromWriterAndRelay();
            $process = $this->runWorker('signal_after_commit', 2);
            self::assertSame(0, $process->getExitCode());
            self::assertStringContainsString('received=1 completed=1', $process->getOutput());
            self::assertSame(1, $this->effectCount($first->outboxId));
            self::assertSame(0, $this->effectCount($second->outboxId));
            $this->assertQueueCount('critical', 1);
            self::assertSame(0, $this->runWorker('normal')->getExitCode());
            self::assertSame(1, $this->effectCount($second->outboxId));
        });
    }

    public function testHardTimeoutLeavesDeliveryForRetry(): void
    {
        $this->withTopology(function (): void {
            $message = $this->originalFromWriterAndRelay();
            $process = $this->runWorker('hard_timeout', 1, 2);
            self::assertNotSame(0, $process->getExitCode());
            self::assertSame(0, $this->effectCount($message->outboxId));
            self::assertSame(0, $this->runWorker('normal')->getExitCode());
            self::assertSame(1, $this->effectCount($message->outboxId));
        });
    }

    public function testOpenTransactionPreventsAckAndRollsBackEffect(): void
    {
        $this->withTopology(function (): void {
            $message = $this->originalFromWriterAndRelay();
            $failure = $this->runWorker('dirty');
            self::assertSame(1, $failure->getExitCode());
            self::assertTrue(str_contains($failure->getErrorOutput(), 'execution_scope_dirty'), 'unexpected_child_stderr');
            self::assertSame(0, $this->effectCount($message->outboxId));
            self::assertSame(0, $this->runWorker('normal')->getExitCode());
            self::assertSame(1, $this->effectCount($message->outboxId));
        });
    }

    /** @return iterable<string, array{bool}> */
    public static function invalidMessages(): iterable
    {
        yield 'unknown contract' => [false];
        yield 'malformed envelope' => [true];
    }

    private function originalFromWriterAndRelay(): BrokerEnvelope
    {
        $updateId = Uuid::uuid7()->toString();
        $correlationId = Uuid::uuid7()->toString();
        $transaction = $this->db->beginTransaction();
        $id = (new DbOutboxWriter($this->db, TestOutboxRoutes::registry()))->write(new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $updateId,
            new TelegramUpdateReceivedPayload($updateId),
            'worker-test.original/' . $updateId,
            $correlationId,
        ))->outboxMessageId;
        $transaction->commit();
        $this->ownedIds[] = $id;
        $settings = new OutboxRelaySettings(5, 600, 15, 900);
        $receipt = (new RelayOutboxHandler(
            new DbOutboxRelayStore($this->db, new OutboxRelayRowMapper(TestOutboxRoutes::registry()), new RamseyOutboxLeaseTokenGenerator()),
            new RabbitMqPublisher(RabbitMqTestEnvironment::factory(), new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), 5.0, TestOutboxRoutes::registry()),
            $settings,
            new OutboxRetryPolicy($settings, new SecureRetryJitter()),
        ))->handle(new RelayOutboxCommand(1));
        self::assertSame(1, $receipt->delivered);

        return new BrokerEnvelope($id, 'telegram.update.received', '1.0', $correlationId, new TelegramUpdateReceivedPayload($updateId));
    }

    private function effectCount(string $originalId): int
    {
        $key = 'worker-test.effect/' . $originalId;
        $rows = $this->db->createCommand('SELECT id FROM {{%outbox_messages}} WHERE idempotency_key = :key', [':key' => $key])->queryColumn();
        foreach ($rows as $id) {
            if (!in_array($id, $this->ownedIds, true)) {
                $this->ownedIds[] = $id;
            }
        }

        return count($rows);
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

    private function runWorker(string $scenario, int $limit = 1, int $maxRuntime = 20): Process
    {
        $process = $this->worker($scenario, $limit, $maxRuntime);
        try {
            $process->run();
        } catch (ProcessSignaledException) {
            self::assertTrue($process->hasBeenSignaled());
        } finally {
            if ($process->isRunning()) {
                $process->stop(0.0, SIGKILL);
            }
        }

        return $process;
    }

    private function worker(string $scenario, int $limit = 1, int $maxRuntime = 20): Process
    {
        return new Process([
            PHP_BINARY, 'tests/bin/critical-worker.php', 'platform-worker/critical',
            '--limit=' . $limit, '--maxRuntime=' . $maxRuntime,
        ], dirname(__DIR__, 5), array_merge(PlatformTestEnvironment::workerEnvironment(), [
            'WORKER_TEST_SCENARIO' => $scenario,
        ]), null, 30.0);
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

    private static function awaitExit(Process $process): void
    {
        try {
            $process->wait();
        } catch (ProcessSignaledException) {
            self::assertTrue($process->hasBeenSignaled());
        }
    }
}
