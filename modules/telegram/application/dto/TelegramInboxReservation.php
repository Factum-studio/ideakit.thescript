<?php

declare(strict_types=1);

namespace modules\telegram\application\dto;

use InvalidArgumentException;
use modules\telegram\application\enum\TelegramInboxReservationOutcome;
use Ramsey\Uuid\Uuid;

final class TelegramInboxReservation
{
    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $inboxId,
        public readonly TelegramInboxReservationOutcome $outcome,
    ) {
        if (!Uuid::isValid($inboxId) || Uuid::fromString($inboxId)->toString() !== $inboxId) {
            throw new InvalidArgumentException('invalid_telegram_inbox_reservation');
        }
    }
}
