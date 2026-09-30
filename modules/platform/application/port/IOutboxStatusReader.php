<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\dto\OutboxStatusView;
use modules\platform\application\exception\OutboxMaintenanceException;

interface IOutboxStatusReader
{
    /** @throws OutboxMaintenanceException */
    public function getStatus(): OutboxStatusView;
}
