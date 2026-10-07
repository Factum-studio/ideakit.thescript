<?php

declare(strict_types=1);

namespace modules\telegram\application\message;

use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\IOutboxPayload;
use Ramsey\Uuid\Uuid;

final class TelegramUpdateReceivedPayload implements IOutboxPayload
{
    /** @throws OutboxWriteException */
    public function __construct(public readonly string $updateId)
    {
        if (!Uuid::isValid($updateId) || Uuid::fromString($updateId)->toString() !== $updateId) {
            throw new OutboxWriteException(OutboxWriteFailure::INVALID_INTENT);
        }
    }

    /** @return array{update_id: string} */
    public function technicalFields(): array
    {
        return ['update_id' => $this->updateId];
    }
}
