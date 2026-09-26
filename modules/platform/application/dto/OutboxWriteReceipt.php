<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\platform\application\exception\OutboxWriteException;
use Ramsey\Uuid\Uuid;

final class OutboxWriteReceipt
{
    /** @throws OutboxWriteException */
    public function __construct(
        public readonly string $outboxMessageId,
        public readonly OutboxWriteOutcome $outcome,
    ) {
        if (!Uuid::isValid($outboxMessageId) || Uuid::fromString($outboxMessageId)->toString() !== $outboxMessageId) {
            throw new OutboxWriteException(OutboxWriteFailure::INVALID_INTENT);
        }
    }
}
