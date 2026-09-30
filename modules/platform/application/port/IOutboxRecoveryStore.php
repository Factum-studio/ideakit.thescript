<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\dto\OutboxRecoveryReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\exception\OutboxMaintenanceException;

interface IOutboxRecoveryStore
{
    /** @throws OutboxMaintenanceException */
    public function recoverExpired(int $limit, OutboxRelaySettings $settings): OutboxRecoveryReceipt;
}
