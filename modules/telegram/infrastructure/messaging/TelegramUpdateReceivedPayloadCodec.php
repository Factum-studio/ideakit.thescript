<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\messaging;

use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\IOutboxPayload;
use modules\platform\application\message\IOutboxPayloadCodec;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;

final class TelegramUpdateReceivedPayloadCodec implements IOutboxPayloadCodec
{
    public function accepts(IOutboxPayload $payload): bool
    {
        return $payload instanceof TelegramUpdateReceivedPayload;
    }

    public function decode(array $technicalFields): IOutboxPayload
    {
        if (array_keys($technicalFields) !== ['update_id'] || !is_string($technicalFields['update_id'])
            || strlen($technicalFields['update_id']) > 1024
        ) {
            throw new OutboxWriteException(OutboxWriteFailure::INVALID_INTENT);
        }

        return new TelegramUpdateReceivedPayload($technicalFields['update_id']);
    }

    public function aggregateId(IOutboxPayload $payload): string
    {
        if (!$payload instanceof TelegramUpdateReceivedPayload) {
            throw new OutboxWriteException(OutboxWriteFailure::UNSUPPORTED_ROUTE);
        }

        return $payload->updateId;
    }
}
