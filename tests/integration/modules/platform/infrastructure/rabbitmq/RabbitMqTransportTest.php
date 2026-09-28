<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure\rabbitmq;

use Codeception\Test\Unit;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\message\TelegramUpdateReceivedPayload;
use modules\platform\infrastructure\rabbitmq\BrokerEnvelopeCodec;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionFactory;
use modules\platform\infrastructure\rabbitmq\RabbitMqPublisher;
use modules\platform\infrastructure\rabbitmq\RabbitMqTopology;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqTransportTest extends Unit
{
    protected function _before(): void
    {
        $connection = RabbitMqTestEnvironment::factory()->connect();
        try {
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
        } finally {
            $connection->close();
        }
    }

    public function testIncompatibleDeclarationPreservesExistingExchange(): void
    {
        $factory = RabbitMqTestEnvironment::factory();
        $connection = $factory->connect();
        $ownsExchange = false;
        try {
            $channel = $connection->channel();
            $channel->exchange_declare('ideakit.commands', 'fanout', false, true, false);
            $ownsExchange = true;
            try {
                (new RabbitMqTopology($factory))->declare();
                self::fail('Expected a topology mismatch.');
            } catch (BrokerTransportException $exception) {
                self::assertSame(BrokerTransportErrorCode::TOPOLOGY_MISMATCH, $exception->errorCode);
                self::assertNull($exception->getPrevious());
                self::assertSame('topology_mismatch', $exception->getMessage());
            }
            $channel->exchange_declare('ideakit.commands', 'fanout', true);
            $channel->exchange_declare('ideakit.commands', 'fanout', false, true, false);
            self::assertTrue($channel->is_open());
        } finally {
            try {
                if ($ownsExchange) {
                    $connection->channel()->exchange_delete('ideakit.commands');
                }
            } finally {
                $connection->close();
            }
        }
    }

    public function testRepeatedDeclarationPreservesTopologyAndMessages(): void
    {
        $factory = RabbitMqTestEnvironment::factory();
        $topology = new RabbitMqTopology($factory);
        $topology->declare();
        $connection = $factory->connect();
        try {
            $channel = $connection->channel();
            foreach (['critical', 'critical.failed'] as $queue) {
                [, $count] = $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
                    'x-queue-type' => 'quorum',
                ]));
                self::assertSame(0, $count);
            }
            $channel->confirm_select();
            $channel->basic_publish(
                new AMQPMessage('synthetic-topology-probe', ['delivery_mode' => 2]),
                'ideakit.commands',
                'critical',
                true,
            );
            $channel->wait_for_pending_acks_returns(5.0);

            $topology->declare();
            [, $count] = $channel->queue_declare('critical', true);
            self::assertSame(1, $count);
            $message = $channel->basic_get('critical');
            self::assertInstanceOf(AMQPMessage::class, $message);
            self::assertSame('synthetic-topology-probe', $message->getBody());
            $message->ack();
            $channel->basic_publish(
                new AMQPMessage('synthetic-error-route'),
                'ideakit.dead-letter',
                'critical.failed',
                true,
            );
            $channel->wait_for_pending_acks_returns(5.0);
            $failed = $channel->basic_get('critical.failed');
            self::assertInstanceOf(AMQPMessage::class, $failed);
            self::assertSame('synthetic-error-route', $failed->getBody());
            $failed->ack();
        } finally {
            $connection->close();
            $cleanup = $factory->connect();
            try {
                $channel = $cleanup->channel();
                $channel->queue_delete('critical');
                $channel->queue_delete('critical.failed');
                $channel->exchange_delete('ideakit.commands');
                $channel->exchange_delete('ideakit.dead-letter');
            } finally {
                $cleanup->close();
            }
        }
    }

    public function testConfirmedPublicationPreservesPropertiesAndIdOnExplicitRepeat(): void
    {
        $this->withTopology(function (AMQPChannel $channel, RabbitMqConnectionFactory $factory): void {
            $codec = new BrokerEnvelopeCodec();
            $publisher = new RabbitMqPublisher($factory, $codec, 5.0);
            $envelope = self::envelope();
            foreach ([1, 2] as $expectedCount) {
                $receipt = $publisher->publish($envelope);
                self::assertSame($envelope->outboxId, $receipt->outboxId);
                [, $count] = $channel->queue_declare('critical', true);
                self::assertSame($expectedCount, $count);
            }
            foreach ([1, 2] as $delivery) {
                $message = $channel->basic_get('critical');
                self::assertInstanceOf(AMQPMessage::class, $message);
                self::assertSame($codec->encode($envelope)->getBody(), $message->getBody());
                self::assertSame('application/json', $message->get('content_type'));
                self::assertSame(2, $message->get('delivery_mode'));
                self::assertSame($envelope->outboxId, $message->get('message_id'));
                self::assertSame($envelope->correlationId, $message->get('correlation_id'));
                self::assertEquals($envelope, $codec->decode($message));
                $message->ack();
            }
            self::assertNull($channel->basic_get('critical'));
        });
    }

    public function testReturnedPublicationIsNotReportedAsConfirmedSuccess(): void
    {
        $this->withTopology(function (AMQPChannel $channel, RabbitMqConnectionFactory $factory): void {
            $channel->queue_unbind('critical', 'ideakit.commands', 'critical');
            try {
                $publisher = new RabbitMqPublisher($factory, new BrokerEnvelopeCodec(), 5.0);
                try {
                    $publisher->publish(self::envelope());
                    self::fail('Expected unroutable publication.');
                } catch (BrokerTransportException $exception) {
                    self::assertSame(BrokerTransportErrorCode::UNROUTABLE, $exception->errorCode);
                    self::assertSame('unroutable', $exception->getMessage());
                    self::assertNull($exception->getPrevious());
                }
                [, $count] = $channel->queue_declare('critical', true);
                self::assertSame(0, $count);
            } finally {
                $channel->queue_bind('critical', 'ideakit.commands', 'critical');
            }
        });
    }

    public function testClosedLocalPortFailsWithoutExposingConnectionContext(): void
    {
        $factory = new RabbitMqConnectionFactory(new RabbitMqConnectionConfig(
            '127.0.0.1',
            1,
            'synthetic-user',
            'synthetic-password',
            'ideakit_transport_test',
            0.2,
        ));
        $publisher = new RabbitMqPublisher($factory, new BrokerEnvelopeCodec(), 0.2);
        $started = hrtime(true);
        try {
            $publisher->publish(self::envelope());
            self::fail('Expected unavailable local connection.');
        } catch (BrokerTransportException $exception) {
            self::assertSame(BrokerTransportErrorCode::CONNECTION_FAILURE, $exception->errorCode);
            self::assertSame('connection_failure', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
        }
    }

    private static function envelope(): BrokerEnvelope
    {
        return new BrokerEnvelope(
            '01890f4d-3c2a-7f48-8c0b-123456789ac4',
            'telegram.update.received',
            '1.0',
            '01890f4d-3c2a-7f48-8c0b-123456789ac5',
            new TelegramUpdateReceivedPayload('01890f4d-3c2a-7f48-8c0b-123456789ac6'),
        );
    }

    /** @param callable(AMQPChannel, RabbitMqConnectionFactory): void $test */
    private function withTopology(callable $test): void
    {
        $factory = RabbitMqTestEnvironment::factory();
        (new RabbitMqTopology($factory))->declare();
        $connection = $factory->connect();
        try {
            $test($connection->channel(), $factory);
        } finally {
            try {
                $channel = $connection->channel();
                $channel->queue_delete('critical');
                $channel->queue_delete('critical.failed');
                $channel->exchange_delete('ideakit.commands');
                $channel->exchange_delete('ideakit.dead-letter');
            } finally {
                $connection->close();
            }
        }
    }
}
