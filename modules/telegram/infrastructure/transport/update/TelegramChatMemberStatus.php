<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

enum TelegramChatMemberStatus: string
{
    case CREATOR = 'creator';
    case ADMINISTRATOR = 'administrator';
    case MEMBER = 'member';
    case RESTRICTED = 'restricted';
    case LEFT = 'left';
    case KICKED = 'kicked';
}
