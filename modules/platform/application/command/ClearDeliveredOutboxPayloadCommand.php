<?php

declare(strict_types=1);

namespace modules\platform\application\command;

use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;

final class ClearDeliveredOutboxPayloadCommand
{
    /** @throws OutboxMaintenanceException */
    public function __construct(public readonly int $limit)
    {
        if ($limit < 1 || $limit > 100) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_LIMIT);
        }
    }
}
