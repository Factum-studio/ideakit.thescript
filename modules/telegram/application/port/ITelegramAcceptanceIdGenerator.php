<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

interface ITelegramAcceptanceIdGenerator
{
    /** Returns a canonical UUIDv7. */
    public function generate(): string;
}
