<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\random;

use modules\platform\application\port\IRetryJitter;

final class SecureRetryJitter implements IRetryJitter
{
    public function between(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }
}
