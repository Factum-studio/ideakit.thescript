<?php

declare(strict_types=1);

namespace modules\users\domain\valueObject;

enum TelegramBotStatus: string
{
    case ACTIVE = 'ACTIVE';
    case BOT_BLOCKED = 'BOT_BLOCKED';
    case ANONYMIZED = 'ANONYMIZED';
}
