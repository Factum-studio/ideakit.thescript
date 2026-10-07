<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\dto\OutboxWriteReceipt;
use modules\platform\application\exception\OutboxWriteException;

interface IOutboxWriter
{
    /** @throws OutboxWriteException */
    public function write(OutboxWriteIntent $intent): OutboxWriteReceipt;
}
