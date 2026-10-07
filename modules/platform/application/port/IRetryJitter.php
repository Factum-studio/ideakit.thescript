<?php

declare(strict_types=1);

namespace modules\platform\application\port;

interface IRetryJitter
{
    public function between(int $minimum, int $maximum): int;
}
