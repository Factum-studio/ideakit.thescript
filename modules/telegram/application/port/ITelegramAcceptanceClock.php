<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

use DateTimeImmutable;

interface ITelegramAcceptanceClock
{
    /** Returns an instant in UTC. */
    public function now(): DateTimeImmutable;
}
