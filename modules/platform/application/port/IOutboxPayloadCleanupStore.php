<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\dto\OutboxPayloadCleanupReceipt;
use modules\platform\application\exception\OutboxMaintenanceException;

interface IOutboxPayloadCleanupStore
{
    /** @throws OutboxMaintenanceException */
    public function clearDue(int $limit): OutboxPayloadCleanupReceipt;
}
