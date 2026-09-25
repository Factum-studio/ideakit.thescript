<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

interface TelegramUpdate
{
    public function updateId(): int;

    public function type(): TelegramUpdateType;
}
