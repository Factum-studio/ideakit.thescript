<?php

declare(strict_types=1);

namespace modules\platform\application\route;

use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\TelegramUpdateReceivedPayload;

final class OutboxRouteRegistry
{
    /** @throws OutboxWriteException */
    public function resolve(OutboxWriteIntent $intent): OutboxRoute
    {
        if (
            $intent->ownerModule !== 'Telegram'
            || $intent->messageType !== 'telegram.update.received'
            || $intent->schemaVersion !== '1.0'
            || $intent->aggregateType !== 'TELEGRAM_UPDATE'
            || !$intent->payload instanceof TelegramUpdateReceivedPayload
        ) {
            throw new OutboxWriteException(OutboxWriteFailure::UNSUPPORTED_ROUTE);
        }

        if ($intent->aggregateId !== $intent->payload->updateId) {
            throw new OutboxWriteException(OutboxWriteFailure::INVALID_INTENT);
        }

        return new OutboxRoute('RABBITMQ', 'critical', 1024);
    }
}
