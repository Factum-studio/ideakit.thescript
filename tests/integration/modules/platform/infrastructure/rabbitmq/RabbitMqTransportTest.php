<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure\rabbitmq;

use Codeception\Test\Unit;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\infrastructure\rabbitmq\BrokerEnvelopeCodec;
use tests\fixtures\platform\TestOutboxRoutes;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionFactory;
use modules\platform\infrastructure\rabbitmq\RabbitMqPublisher;
use modules\platform\infrastructure\rabbitmq\RabbitMqReceiver;
use modules\platform\infrastructure\rabbitmq\RabbitMqTopology;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
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
            $codec = new BrokerEnvelopeCodec(TestOutboxRoutes::registry());
            $publisher = new RabbitMqPublisher($factory, $codec, 5.0, TestOutboxRoutes::registry());
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
                $publisher = new RabbitMqPublisher($factory, new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), 5.0, TestOutboxRoutes::registry());
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
        $publisher = new RabbitMqPublisher($factory, new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), 0.2, TestOutboxRoutes::registry());
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
        $receiver = new RabbitMqReceiver($factory, new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), 0.2);
        try {
            self::assertTransportError(BrokerTransportErrorCode::CONNECTION_FAILURE, static fn () => $receiver->receive(0.2));
        } finally {
            $receiver->close();
        }
    }

    public function testManualAckAndPrefetchLimitOutstandingDelivery(): void
    {
        $this->withTopology(function (AMQPChannel $channel, RabbitMqConnectionFactory $factory): void {
            $codec = new BrokerEnvelopeCodec(TestOutboxRoutes::registry());
            $publisher = new RabbitMqPublisher($factory, $codec, 5.0, TestOutboxRoutes::registry());
            $first = self::envelope();
            $second = new BrokerEnvelope(
                '01890f4d-3c2a-7f48-8c0b-123456789ad4',
                'telegram.update.received',
                '1.0',
                $first->correlationId,
                $first->payload,
            );
            $publisher->publish($first);
            $publisher->publish($second);
            $receiver = new RabbitMqReceiver($factory, $codec, 0.1, 4);
            try {
                $delivery = $receiver->receive(5.0);
                self::assertNotNull($delivery);
                self::assertEquals($first, $delivery->message());
                self::assertNull($receiver->receive(0.2));
                $delivery->acknowledge();
                self::assertTransportError(BrokerTransportErrorCode::DELIVERY_ALREADY_SETTLED, $delivery->reject(...));
                $next = $receiver->receive(5.0);
                self::assertNotNull($next);
                self::assertEquals($second, $next->message());
                $next->acknowledge();
                self::assertTransportError(BrokerTransportErrorCode::DELIVERY_ALREADY_SETTLED, $next->acknowledge(...));
                self::assertNull($receiver->receive(0.2));
            } finally {
                $receiver->close();
            }
            $replacement = new RabbitMqReceiver($factory, $codec, 0.1);
            try {
                self::assertNull($replacement->receive(0.2));
            } finally {
                $replacement->close();
            }
        });
    }

    public function testClosingBeforeAckAllowsRedeliveryAndInvalidatesOldDelivery(): void
    {
        $this->withTopology(function (AMQPChannel $channel, RabbitMqConnectionFactory $factory): void {
            $codec = new BrokerEnvelopeCodec(TestOutboxRoutes::registry());
            (new RabbitMqPublisher($factory, $codec, 5.0, TestOutboxRoutes::registry()))->publish(self::envelope());
            $receiver = new RabbitMqReceiver($factory, $codec, 0.1);
            try {
                $delivery = $receiver->receive(5.0);
                self::assertNotNull($delivery);
                self::assertEquals(self::envelope(), $delivery->message());
            } finally {
                $receiver->close();
            }
            self::assertTransportError(BrokerTransportErrorCode::DELIVERY_UNAVAILABLE, $delivery->acknowledge(...));
            self::assertTransportError(BrokerTransportErrorCode::DELIVERY_UNAVAILABLE, $delivery->reject(...));
            $replacement = new RabbitMqReceiver($factory, $codec, 0.1);
            try {
                $redelivery = $replacement->receive(5.0);
                self::assertNotNull($redelivery);
                self::assertEquals(self::envelope(), $redelivery->message());
                $redelivery->acknowledge();
                self::assertNull($replacement->receive(0.2));
            } finally {
                $replacement->close();
            }
        });
    }

    /** @dataProvider incompatibleWorkerHeartbeat */
    public function testWorkerHeartbeatGuardRefusesBeforeSubscribe(int $heartbeat, float $poll): void
    {
        $this->withTopology(function (AMQPChannel $channel) use ($heartbeat, $poll): void {
            $factory = new RabbitMqConnectionFactory(new RabbitMqConnectionConfig(
                $_ENV['TEST_RABBITMQ_HOST'],
                (int) $_ENV['TEST_RABBITMQ_PORT'],
                $_ENV['TEST_RABBITMQ_USER'],
                $_ENV['TEST_RABBITMQ_PASSWORD'],
                $_ENV['TEST_RABBITMQ_VHOST'],
                heartbeat: $heartbeat,
            ));
            $receiver = new RabbitMqReceiver($factory, new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), $poll, 4);
            try {
                self::assertTransportError(BrokerTransportErrorCode::CONFIGURATION_INVALID, static fn () => $receiver->receive(1.0));
                [, , $consumers] = $channel->queue_declare('critical', true);
                self::assertSame(0, $consumers);
            } finally {
                $receiver->close();
            }
        });
    }

    /** @return iterable<string, array{int, float}> */
    public static function incompatibleWorkerHeartbeat(): iterable
    {
        yield 'negotiated heartbeat too short' => [2, 0.1];
        yield 'poll exceeds negotiated heartbeat half' => [10, 5.1];
    }

    public function testRejectDeadLettersWithBrokerMetadataAndNoAutomaticReturn(): void
    {
        $this->withTopology(function (AMQPChannel $channel, RabbitMqConnectionFactory $factory): void {
            $codec = new BrokerEnvelopeCodec(TestOutboxRoutes::registry());
            (new RabbitMqPublisher($factory, $codec, 5.0, TestOutboxRoutes::registry()))->publish(self::envelope());
            $receiver = new RabbitMqReceiver($factory, $codec, 0.1);
            try {
                $delivery = $receiver->receive(5.0);
                self::assertNotNull($delivery);
                $delivery->reject();
                self::assertTransportError(BrokerTransportErrorCode::DELIVERY_ALREADY_SETTLED, $delivery->acknowledge(...));
                $failed = self::awaitFailedMessage($channel, 5.0);
                self::assertInstanceOf(AMQPMessage::class, $failed);
                self::assertSame($codec->encode(self::envelope())->getBody(), $failed->getBody());
                $headers = $failed->get('application_headers')->getNativeData();
                self::assertSame('rejected', $headers['x-death'][0]['reason']);
                self::assertSame('critical', $headers['x-death'][0]['queue']);
                $failed->ack();
                self::assertNull($receiver->receive(0.2));
            } finally {
                $receiver->close();
            }
        });
    }

    public function testDeadLetterIsRetainedUntilErrorBindingIsRestored(): void
    {
        $this->withTopology(function (AMQPChannel $channel, RabbitMqConnectionFactory $factory): void {
            $codec = new BrokerEnvelopeCodec(TestOutboxRoutes::registry());
            $receiver = new RabbitMqReceiver($factory, $codec, 0.1);
            $channel->queue_unbind('critical.failed', 'ideakit.dead-letter', 'critical.failed');
            try {
                (new RabbitMqPublisher($factory, $codec, 5.0, TestOutboxRoutes::registry()))->publish(self::envelope());
                $delivery = $receiver->receive(5.0);
                self::assertNotNull($delivery);
                $delivery->reject();
                self::assertNull($receiver->receive(0.2));
                $receiver->close();
                self::assertNull(self::awaitFailedMessage($channel, 1.0));
                $channel->queue_bind('critical.failed', 'ideakit.dead-letter', 'critical.failed');
                $failed = self::awaitFailedMessage($channel, 195.0);
                self::assertInstanceOf(AMQPMessage::class, $failed);
                self::assertSame($codec->encode(self::envelope())->getBody(), $failed->getBody());
                $failed->ack();
            } finally {
                try {
                    $channel->queue_bind('critical.failed', 'ideakit.dead-letter', 'critical.failed');
                } finally {
                    $receiver->close();
                }
            }
        });
    }

    /** @dataProvider invalidBodies */
    public function testInvalidBodyRemainsRejectable(string $body): void
    {
        $this->withTopology(function (AMQPChannel $channel, RabbitMqConnectionFactory $factory) use ($body): void {
            $channel->confirm_select();
            $channel->basic_publish(new AMQPMessage($body, ['delivery_mode' => 2]), 'ideakit.commands', 'critical', true);
            $channel->wait_for_pending_acks_returns(5.0);
            $receiver = new RabbitMqReceiver($factory, new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), 0.1);
            try {
                $delivery = $receiver->receive(5.0);
                self::assertNotNull($delivery);
                self::assertTransportError(BrokerTransportErrorCode::INVALID_ENVELOPE, $delivery->message(...));
                $delivery->reject();
                $failed = self::awaitFailedMessage($channel, 5.0);
                self::assertInstanceOf(AMQPMessage::class, $failed);
                self::assertSame($body, $failed->getBody());
                $failed->ack();
            } finally {
                $receiver->close();
            }
        });
    }

    /** @return array<string, array{string}> */
    public static function invalidBodies(): array
    {
        return ['malformed' => ['{'], 'oversized' => [str_repeat('x', 4097)]];
    }

    /** @param callable(): mixed $operation */
    private static function assertTransportError(BrokerTransportErrorCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected transport failure.');
        } catch (BrokerTransportException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($code->value, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private static function awaitFailedMessage(AMQPChannel $channel, float $timeout): ?AMQPMessage
    {
        $message = null;
        $channel->basic_qos(0, 1, false);
        $tag = $channel->basic_consume('critical.failed', '', false, false, false, false, static function (AMQPMessage $received) use (&$message): void {
            $message = $received;
        });
        $deadline = hrtime(true) / 1e9 + $timeout;
        try {
            while ($message === null && ($remaining = $deadline - hrtime(true) / 1e9) > 0.0) {
                try {
                    $channel->wait(null, false, min($remaining, 1.0));
                } catch (AMQPTimeoutException) {
                    // Keep servicing heartbeat until the original deadline.
                }
            }
        } finally {
            $channel->basic_cancel($tag);
        }

        return $message;
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
