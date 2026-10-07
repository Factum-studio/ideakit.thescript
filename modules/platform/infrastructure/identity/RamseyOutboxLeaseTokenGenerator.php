<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\identity;

use modules\platform\application\port\IOutboxLeaseTokenGenerator;
use Ramsey\Uuid\Uuid;

final class RamseyOutboxLeaseTokenGenerator implements IOutboxLeaseTokenGenerator
{
    public function generate(): string
    {
        return Uuid::uuid4()->toString();
    }
}
