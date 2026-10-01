<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use Exception;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\BrokerPublishReceipt;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\port\IBrokerPublisher;
use modules\platform\application\route\OutboxRouteRegistry;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMqPublisher implements IBrokerPublisher
{
    public function __construct(
        private readonly RabbitMqConnectionFactory $factory,
        private readonly BrokerEnvelopeCodec $codec,
        private readonly float $confirmTimeout,
        private readonly OutboxRouteRegistry $routes,
    ) {
        if (!is_finite($confirmTimeout) || $confirmTimeout <= 0.0 || $confirmTimeout > 30.0) {
            throw new BrokerTransportException(BrokerTransportErrorCode::CONFIGURATION_INVALID);
        }
    }

    public function publish(BrokerEnvelope $envelope): BrokerPublishReceipt
    {
        $route = $this->routes->find($envelope->messageType, $envelope->schemaVersion);
        if ($route === null || $route->destination !== 'RABBITMQ'
            || !$route->payloadCodec->accepts($envelope->payload)
        ) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }
        $message = $this->codec->encode($envelope);
        $connection = $this->factory->connectForPublication();
        $channel = null;
        $failure = null;
        try {
            $channel = $connection->channel();
            $confirmation = new PublishConfirmation();
            $channel->confirm_select();
            $channel->set_ack_handler(static function (AMQPMessage $message) use ($confirmation): void {
                $confirmation->acknowledge();
            });
            $channel->set_nack_handler(static function (AMQPMessage $message) use ($confirmation): void {
                $confirmation->reject();
            });
            $channel->set_return_listener(static function () use ($confirmation): void {
                $confirmation->returned();
            });
            $channel->basic_publish($message, 'ideakit.commands', $route->routingKey, true);
            $confirmation->await(
                static function (float $remaining) use ($channel, $connection): void {
                    $connection->waitForConfirmation($channel, $remaining);
                },
                static fn (): float => hrtime(true) / 1e9,
                $this->confirmTimeout,
            );
        } catch (BrokerTransportException $exception) {
            $failure = $exception;
        } catch (Exception) {
            $failure = new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
        } finally {
            try {
                $channel?->close();
            } catch (Exception) {
                $failure ??= new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
            }
            try {
                $connection->close();
            } catch (Exception) {
                $failure ??= new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
            }
        }
        if ($failure !== null) {
            throw $failure;
        }

        return new BrokerPublishReceipt($envelope->outboxId);
    }
}
