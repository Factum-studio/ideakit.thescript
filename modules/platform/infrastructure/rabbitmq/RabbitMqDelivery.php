<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use Closure;
use Exception;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\port\IBrokerDelivery;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMqDelivery implements IBrokerDelivery
{
    private bool $settled = false;

    /** @param Closure(): void $invalidate */
    public function __construct(
        private readonly AMQPMessage $rawMessage,
        private readonly BrokerEnvelopeCodec $codec,
        private readonly Closure $invalidate,
    ) {
    }

    public function message(): BrokerEnvelope
    {
        $this->assertAvailable();

        return $this->codec->decode($this->rawMessage);
    }

    public function acknowledge(): void
    {
        $this->settle(false);
    }

    public function reject(): void
    {
        $this->settle(true);
    }

    private function settle(bool $reject): void
    {
        if ($this->settled) {
            throw new BrokerTransportException(BrokerTransportErrorCode::DELIVERY_ALREADY_SETTLED);
        }
        $this->assertAvailable();
        try {
            if ($reject) {
                $this->rawMessage->reject(false);
            } else {
                $this->rawMessage->ack(false);
            }
        } catch (Exception) {
            try {
                ($this->invalidate)();
            } catch (BrokerTransportException) {
                // A cleanup failure must not hide the failed settlement.
            }

            throw new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
        }
        $this->settled = true;
    }

    private function assertAvailable(): void
    {
        if (!$this->rawMessage->getChannel()?->is_open()) {
            throw new BrokerTransportException(BrokerTransportErrorCode::DELIVERY_UNAVAILABLE);
        }
    }
}
