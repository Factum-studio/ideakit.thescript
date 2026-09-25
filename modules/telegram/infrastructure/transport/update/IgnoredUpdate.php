<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

use InvalidArgumentException;

final class IgnoredUpdate implements TelegramUpdate
{
    public function __construct(
        private readonly int $updateId,
        public readonly TelegramUpdateType $sourceType,
        public readonly IgnoredUpdateReason $reason,
    ) {
        if ($updateId < 0) {
            throw new InvalidArgumentException('invalid_update_id');
        }
    }

    public function updateId(): int
    {
        return $this->updateId;
    }

    public function type(): TelegramUpdateType
    {
        return $this->sourceType;
    }
}
