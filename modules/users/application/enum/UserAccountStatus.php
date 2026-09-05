<?php

declare(strict_types=1);

namespace modules\users\application\enum;

enum UserAccountStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
