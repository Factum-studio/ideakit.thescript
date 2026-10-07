<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

enum TelegramMessageIntent
{
    case START;
    case NEXT_IDEA;
    case CANCEL;
    case OTHER_TEXT;
}
