<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\message\IOutboxPayload;
use Ramsey\Uuid\Uuid;

final class BrokerEnvelope
{
    /** @throws BrokerTransportException */
    public function __construct(
        public readonly string $outboxId,
        public readonly string $messageType,
        public readonly string $schemaVersion,
        public readonly string $correlationId,
        public readonly IOutboxPayload $payload,
    ) {
        foreach ([$outboxId, $correlationId] as $id) {
            if (!Uuid::isValid($id) || Uuid::fromString($id)->toString() !== $id) {
                throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
            }
        }
        foreach ([[$messageType, 64], [$schemaVersion, 48]] as [$value, $limit]) {
            if (!mb_check_encoding($value, 'UTF-8') || trim($value) === ''
                || mb_strlen($value, 'UTF-8') > $limit || preg_match('/[\x00-\x1f\x7f]/', $value) !== 0
            ) {
                throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
            }
        }
    }
}
