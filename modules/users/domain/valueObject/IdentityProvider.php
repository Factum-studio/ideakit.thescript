<?php

declare(strict_types=1);

namespace modules\users\domain\valueObject;

enum IdentityProvider: string
{
    case TELEGRAM = 'TELEGRAM';
    case INTERNAL = 'INTERNAL';
    case PASSPORT = 'PASSPORT';
}
