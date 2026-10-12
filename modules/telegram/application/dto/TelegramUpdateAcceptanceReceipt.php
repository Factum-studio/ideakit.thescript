<?php

declare(strict_types=1);

namespace modules\telegram\application\dto;

use InvalidArgumentException;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use Ramsey\Uuid\Uuid;

final class TelegramUpdateAcceptanceReceipt
{
    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $inboxId,
        public readonly TelegramUpdateAcceptanceOutcome $outcome,
    ) {
        if (!Uuid::isValid($inboxId) || Uuid::fromString($inboxId)->toString() !== $inboxId) {
            throw new InvalidArgumentException('invalid_telegram_update_acceptance_receipt');
        }
    }
}
