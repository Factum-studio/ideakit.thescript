<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\application\command\RelayOutboxCommand;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\handler\RelayOutboxHandler;
use modules\platform\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\route\OutboxRouteRegistry;
use modules\platform\infrastructure\db\DbOutboxRelayStore;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\platform\infrastructure\db\OutboxRelayRowMapper;
use modules\platform\infrastructure\identity\RamseyOutboxLeaseTokenGenerator;
use modules\platform\infrastructure\rabbitmq\BrokerEnvelopeCodec;
use modules\platform\infrastructure\rabbitmq\RabbitMqPublisher;
use modules\platform\infrastructure\rabbitmq\RabbitMqReceiver;
use modules\platform\infrastructure\rabbitmq\RabbitMqTopology;
use modules\platform\infrastructure\random\SecureRetryJitter;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use Ramsey\Uuid\Uuid;
use tests\integration\modules\platform\infrastructure\rabbitmq\RabbitMqTestEnvironment;
use Yii;
use yii\db\Connection;

final class OutboxRelayRabbitMqTest extends Unit
{
    private Connection $db;
    private ?string $ownedId = null;

    protected function _before(): void
    {
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
            if ($this->ownedId !== null) {
                $this->db->createCommand()->delete('{{%outbox_messages}}', ['id' => $this->ownedId])->execute();
            }
        }
    }

    /** @dataProvider routing */
    public function testRealBrokerOutcomeIsPersisted(bool $routed): void
    {
        $lock = fopen(sys_get_temp_dir() . '/ideakit-broker-integration.lock', 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $connection = null;
        $receiver = null;
        $ownsTopology = false;
        $unbound = false;
        try {
            $factory = RabbitMqTestEnvironment::factory();
            $connection = $factory->connect();
            self::requireAbsentTopology($connection);
            $ownsTopology = true;
            (new RabbitMqTopology($factory))->declare();
            $channel = $connection->channel();
            if (!$routed) {
                $channel->queue_unbind('critical', 'ideakit.commands', 'critical');
                $unbound = true;
            }
            $update = Uuid::uuid7()->toString();
            $correlation = Uuid::uuid7()->toString();
            $transaction = $this->db->beginTransaction();
            $this->ownedId = (new DbOutboxWriter($this->db, new OutboxRouteRegistry()))->write(new OutboxWriteIntent(
                'Telegram',
                'telegram.update.received',
                '1.0',
                'TELEGRAM_UPDATE',
                $update,
                new TelegramUpdateReceivedPayload($update),
                'relay-broker-' . $update,
                $correlation,
            ))->outboxMessageId;
            $transaction->commit();
            $settings = new OutboxRelaySettings(5, 600, 15, 900);
            $receipt = (new RelayOutboxHandler(
                new DbOutboxRelayStore($this->db, new OutboxRelayRowMapper(new OutboxRouteRegistry()), new RamseyOutboxLeaseTokenGenerator()),
                new RabbitMqPublisher($factory, new BrokerEnvelopeCodec(), 5.0),
                $settings,
                new OutboxRetryPolicy($settings, new SecureRetryJitter()),
            ))->handle(new RelayOutboxCommand(1));
            $row = $this->db->createCommand('SELECT * FROM {{%outbox_messages}} WHERE id = :id', [':id' => $this->ownedId])->queryOne();
            self::assertIsArray($row);
            self::assertSame(1, $receipt->claimed);
            self::assertSame($routed ? 'DELIVERED' : 'FAILED', $row['status']);
            self::assertSame($routed ? null : 'unroutable', $row['last_error_code']);
            if ($routed) {
                self::assertSame(1, $receipt->delivered);
                $receiver = new RabbitMqReceiver($factory, new BrokerEnvelopeCodec(), 1.0);
                $delivery = $receiver->receive(5.0);
                self::assertNotNull($delivery);
                $envelope = $delivery->message();
                self::assertSame($this->ownedId, $envelope->outboxId);
                self::assertSame($correlation, $envelope->correlationId);
                self::assertSame(['update_id' => $update], $envelope->payload->technicalFields());
                $delivery->acknowledge();
            } else {
                self::assertSame(1, $receipt->failed);
                [, $count] = $channel->queue_declare('critical', true);
                self::assertSame(0, $count);
            }
        } finally {
            try {
                $receiver?->close();
            } finally {
                try {
                    if ($connection !== null && $ownsTopology) {
                        $channel = $connection->channel();
                        if ($unbound) {
                            $channel->queue_bind('critical', 'ideakit.commands', 'critical');
                        }
                        $channel->queue_delete('critical');
                        $channel->queue_delete('critical.failed');
                        $channel->exchange_delete('ideakit.commands');
                        $channel->exchange_delete('ideakit.dead-letter');
                    }
                } finally {
                    try {
                        $connection?->close();
                    } finally {
                        flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }
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

    /** @return iterable<string, array{bool}> */
    public static function routing(): iterable
    {
        yield 'confirmed' => [true];
        yield 'returned despite confirm' => [false];
    }
}
