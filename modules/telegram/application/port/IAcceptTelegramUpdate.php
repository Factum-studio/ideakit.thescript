<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\dto\TelegramUpdateAcceptanceReceipt;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;

interface IAcceptTelegramUpdate
{
    /**
     * Returns only after durable acceptance or a confirmed duplicate.
     *
     * @throws TelegramUpdateAcceptanceUnavailableException
     */
    public function handle(AcceptTelegramUpdateCommand $command): TelegramUpdateAcceptanceReceipt;
}
