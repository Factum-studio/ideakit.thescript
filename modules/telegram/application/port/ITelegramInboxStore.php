<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\dto\TelegramInboxReservation;
use modules\telegram\application\exception\InvalidTelegramUpdatePayloadException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceIntegrityException;

interface ITelegramInboxStore
{
    /**
     * Reserves within the caller's transaction without changing an existing update.
     *
     * @throws InvalidTelegramUpdatePayloadException
     * @throws TelegramUpdateAcceptanceIntegrityException
     */
    public function reserve(AcceptTelegramUpdateCommand $command): TelegramInboxReservation;
}
