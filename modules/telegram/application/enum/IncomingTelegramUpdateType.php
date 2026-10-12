<?php

declare(strict_types=1);

namespace modules\telegram\application\enum;

enum IncomingTelegramUpdateType: string
{
    case MESSAGE = 'MESSAGE';
    case CALLBACK_QUERY = 'CALLBACK_QUERY';
    case MY_CHAT_MEMBER = 'MY_CHAT_MEMBER';
    case UNSUPPORTED = 'UNSUPPORTED';
}
