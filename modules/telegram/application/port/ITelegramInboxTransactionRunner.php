<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

use modules\telegram\application\dto\TelegramInboxReservation;
use modules\telegram\application\exception\InvalidTelegramUpdatePayloadException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceIntegrityException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;

interface ITelegramInboxTransactionRunner
{
    /**
     * Returns only after its own top-level commit; rejects an active caller transaction.
     *
     * @param callable(): TelegramInboxReservation $operation
     * @throws InvalidTelegramUpdatePayloadException
     * @throws TelegramUpdateAcceptanceIntegrityException
     * @throws TelegramUpdateAcceptanceUnavailableException
     */
    public function run(callable $operation): TelegramInboxReservation;
}
