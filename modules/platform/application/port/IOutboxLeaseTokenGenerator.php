<?php

declare(strict_types=1);

namespace modules\platform\application\port;

interface IOutboxLeaseTokenGenerator
{
    public function generate(): string;
}
