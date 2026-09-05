<?php

declare(strict_types=1);

namespace modules\users\application\enum;

enum TelegramProfileBlockReason: string
{
    case BOT_BLOCKED_BY_USER = 'BOT_BLOCKED_BY_USER';
}
