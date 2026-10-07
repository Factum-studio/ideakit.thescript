<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;

final class OutboxRecoveryReceipt
{
    /** @throws OutboxMaintenanceException */
    public function __construct(
        public readonly int $retryScheduled,
        public readonly int $failed,
    ) {
        if ($retryScheduled < 0 || $failed < 0) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
        }
    }
}
