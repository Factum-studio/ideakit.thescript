<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure\rabbitmq;

use Codeception\Test\Unit;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\infrastructure\rabbitmq\RabbitMqTopology;
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
}
