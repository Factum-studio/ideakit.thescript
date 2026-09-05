<?php

declare(strict_types=1);

namespace modules\users\application\port;

use modules\users\domain\valueObject\TelegramIdentityProfileId;

interface ITelegramIdentityProfileIdGenerator
{
    public function generate(): TelegramIdentityProfileId;
}
