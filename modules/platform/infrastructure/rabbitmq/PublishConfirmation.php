<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use Exception;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use PhpAmqpLib\Exception\AMQPTimeoutException;

final class PublishConfirmation
{
    private bool $acknowledged = false;
    private bool $nacked = false;
    private bool $wasReturned = false;

    public function acknowledge(): void
    {
        $this->acknowledged = true;
    }

    public function reject(): void
    {
        $this->nacked = true;
    }

    public function returned(): void
    {
        $this->wasReturned = true;
    }

    /**
     * @param callable(float): void $pump
     * @param callable(): float $now
     * @throws BrokerTransportException
     */
    public function await(callable $pump, callable $now, float $timeout): void
    {
        $deadline = $now() + $timeout;
        while (!$this->acknowledged && !$this->nacked) {
            $remaining = $deadline - $now();
            if ($remaining <= 0.0) {
                throw new BrokerTransportException(BrokerTransportErrorCode::CONFIRM_TIMEOUT);
            }
            try {
                $pump($remaining);
            } catch (AMQPTimeoutException) {
                throw new BrokerTransportException(BrokerTransportErrorCode::CONFIRM_TIMEOUT);
            } catch (Exception) {
                throw new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
            }
            if ($now() >= $deadline) {
                throw new BrokerTransportException(BrokerTransportErrorCode::CONFIRM_TIMEOUT);
            }
        }
        if ($this->wasReturned) {
            throw new BrokerTransportException(BrokerTransportErrorCode::UNROUTABLE);
        }
        if ($this->nacked) {
            throw new BrokerTransportException(BrokerTransportErrorCode::NACKED);
        }
    }
}
