<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\time;

use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\application\port\ITelegramAcceptanceClock;

final class SystemTelegramAcceptanceClock implements ITelegramAcceptanceClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
