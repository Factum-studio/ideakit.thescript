<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use Exception;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\port\IBrokerDelivery;
use modules\platform\application\port\IBrokerReceiver;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMqReceiver implements IBrokerReceiver
{
    private ?AbstractConnection $connection = null;
    private ?AMQPChannel $channel = null;
    private ?IBrokerDelivery $pending = null;
    private bool $closed = false;

    public function __construct(
        private readonly RabbitMqConnectionFactory $factory,
        private readonly BrokerEnvelopeCodec $codec,
        private readonly float $pollTimeout,
    ) {
        self::validateTimeout($pollTimeout);
    }

    public function receive(float $timeoutSeconds): ?IBrokerDelivery
    {
        self::validateTimeout($timeoutSeconds);
        if ($this->closed) {
            throw new BrokerTransportException(BrokerTransportErrorCode::DELIVERY_UNAVAILABLE);
        }
        try {
            if ($this->channel === null) {
                $this->connection = $this->factory->connect();
                $this->channel = $this->connection->channel();
                $this->channel->setBodySizeLimit(4096);
                $this->channel->basic_qos(0, 1, false);
                $this->channel->basic_consume('critical', '', false, false, false, false, function (AMQPMessage $message): void {
                    $this->pending = new RabbitMqDelivery($message, $this->codec, $this->close(...));
                });
            }
            $deadline = hrtime(true) / 1e9 + $timeoutSeconds;
            while ($this->pending === null) {
                if (!$this->channel->is_open() || !$this->channel->is_consuming()) {
                    throw new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
                }
                $remaining = $deadline - hrtime(true) / 1e9;
                if ($remaining <= 0.0) {
                    return null;
                }
                try {
                    $this->channel->wait(null, false, min($remaining, $this->pollTimeout));
                } catch (AMQPTimeoutException) {
                    // A bounded idle poll is not a connection failure.
                }
            }
            $delivery = $this->pending;
            $this->pending = null;

            return $delivery;
        } catch (Exception) {
            try {
                $this->close();
            } catch (BrokerTransportException) {
                // Cleanup must not replace the original receive failure.
            }

            throw new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
        }
    }

    public function close(): void
    {
        $this->closed = true;
        $failure = null;
        try {
            if ($this->channel?->is_open()) {
                $this->channel->close();
            }
        } catch (Exception) {
            $failure = new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
        }
        try {
            $this->connection?->close();
        } catch (Exception) {
            $failure ??= new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
        } finally {
            $this->channel = null;
            $this->connection = null;
            $this->pending = null;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function validateTimeout(float $timeout): void
    {
        if (!is_finite($timeout) || $timeout <= 0.0 || $timeout > 30.0) {
            throw new BrokerTransportException(BrokerTransportErrorCode::CONFIGURATION_INVALID);
        }
    }
}
