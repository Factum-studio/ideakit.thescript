<?php

declare(strict_types=1);

namespace modules\telegram\application\enum;

enum TelegramUpdateAcceptanceOutcome: string
{
    case ACCEPTED = 'ACCEPTED';
    case DUPLICATE = 'DUPLICATE';
}
