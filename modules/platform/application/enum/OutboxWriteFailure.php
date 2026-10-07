<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum OutboxWriteFailure: string
{
    case INVALID_INTENT = 'invalid_intent';
    case UNSUPPORTED_ROUTE = 'unsupported_route';
    case TRANSACTION_REQUIRED = 'transaction_required';
    case IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
    case PERSISTENCE_FAILURE = 'persistence_failure';
}
