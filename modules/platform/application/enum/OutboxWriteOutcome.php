<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum OutboxWriteOutcome: string
{
    case CREATED = 'CREATED';
    case ALREADY_EXISTS = 'ALREADY_EXISTS';
}
