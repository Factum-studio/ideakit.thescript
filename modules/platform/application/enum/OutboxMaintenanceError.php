<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum OutboxMaintenanceError: string
{
    case INVALID_LIMIT = 'invalid_limit';
    case TRANSACTION_ALREADY_ACTIVE = 'transaction_already_active';
    case PERSISTENCE_FAILURE = 'persistence_failure';
    case INVALID_STATE = 'invalid_state';
}
