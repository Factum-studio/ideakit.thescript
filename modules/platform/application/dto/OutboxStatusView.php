<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;

final class OutboxStatusView
{
    /** @throws OutboxMaintenanceException */
    public function __construct(
        public readonly int $failed,
        public readonly int $expiredLeases,
        public readonly int $due,
    ) {
        if ($failed < 0 || $expiredLeases < 0 || $due < 0) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
        }
    }
}
