<?php

declare(strict_types=1);

namespace modules\users\infrastructure\identity;

use modules\users\application\port\ITelegramIdentityProfileIdGenerator;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use Ramsey\Uuid\Uuid;

final class RamseyTelegramIdentityProfileIdGenerator implements ITelegramIdentityProfileIdGenerator
{
    public function generate(): TelegramIdentityProfileId
    {
        return new TelegramIdentityProfileId(Uuid::uuid7()->toString());
    }
}
