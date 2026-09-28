<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use Ramsey\Uuid\Uuid;

final class BrokerPublishReceipt
{
    /** @throws BrokerTransportException */
    public function __construct(public readonly string $outboxId)
    {
        if (!Uuid::isValid($outboxId) || Uuid::fromString($outboxId)->toString() !== $outboxId) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }
    }
}
