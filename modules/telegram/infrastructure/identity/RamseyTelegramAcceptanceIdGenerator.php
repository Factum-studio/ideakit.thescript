<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\identity;

use modules\telegram\application\port\ITelegramAcceptanceIdGenerator;
use Ramsey\Uuid\Uuid;

final class RamseyTelegramAcceptanceIdGenerator implements ITelegramAcceptanceIdGenerator
{
    public function generate(): string
    {
        return Uuid::uuid7()->toString();
    }
}
