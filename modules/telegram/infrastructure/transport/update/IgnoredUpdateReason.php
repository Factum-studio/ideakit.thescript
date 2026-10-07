<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

enum IgnoredUpdateReason: string
{
    case NON_PRIVATE_CHAT = 'NON_PRIVATE_CHAT';
    case UNSUPPORTED_UPDATE_TYPE = 'UNSUPPORTED_UPDATE_TYPE';
    case UNSUPPORTED_CONTENT = 'UNSUPPORTED_CONTENT';
    case UNSUPPORTED_CALLBACK_CONTEXT = 'UNSUPPORTED_CALLBACK_CONTEXT';
    case INVALID_STRUCTURE = 'INVALID_STRUCTURE';
    case UNSUPPORTED_ACTOR = 'UNSUPPORTED_ACTOR';
}
