<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use Exception;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\port\IBrokerTopology;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqTopology implements IBrokerTopology
{
    public function __construct(private readonly RabbitMqConnectionFactory $factory)
    {
    }

    public function declare(): void
    {
        $connection = $this->factory->connect();
        $failure = null;
        try {
            $channel = $connection->channel();
            $channel->exchange_declare('ideakit.commands', 'direct', false, true, false);
            $channel->exchange_declare('ideakit.dead-letter', 'direct', false, true, false);
            foreach (['critical', 'critical.failed'] as $queue) {
                $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
                    'x-queue-type' => 'quorum',
                ]));
            }
            $channel->queue_bind('critical', 'ideakit.commands', 'critical');
            $channel->queue_bind('critical.failed', 'ideakit.dead-letter', 'critical.failed');
        } catch (Exception $exception) {
            $failure = new BrokerTransportException(
                $exception instanceof AMQPProtocolChannelException && $exception->getCode() === 406
                    ? BrokerTransportErrorCode::TOPOLOGY_MISMATCH
                    : BrokerTransportErrorCode::CONNECTION_FAILURE,
            );
        } finally {
            try {
                $connection->close();
            } catch (Exception) {
                $failure ??= new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
