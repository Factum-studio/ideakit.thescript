<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;

final class OutboxPayloadCleanupReceipt
{
    /** @throws OutboxMaintenanceException */
    public function __construct(public readonly int $cleared)
    {
        if ($cleared < 0) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
        }
    }
}
