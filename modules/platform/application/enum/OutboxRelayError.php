<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum OutboxRelayError: string
{
    case INVALID_MESSAGE = 'invalid_message';
    case UNSUPPORTED_ROUTE = 'unsupported_route';
    case ATTEMPT_LIMIT_REACHED = 'attempt_limit_reached';
    case CONFIRM_MISMATCH = 'confirm_mismatch';
    case CONNECTION_FAILURE = 'connection_failure';
    case NACKED = 'nacked';
    case CONFIRM_TIMEOUT = 'confirm_timeout';
    case LEASE_EXPIRED = 'lease_expired';
    case UNROUTABLE = 'unroutable';
    case CONFIGURATION_INVALID = 'configuration_invalid';
    case TOPOLOGY_MISMATCH = 'topology_mismatch';
    case PERSISTENCE_FAILURE = 'persistence_failure';
    case TRANSACTION_ALREADY_ACTIVE = 'transaction_already_active';
    case UNEXPECTED_FAILURE = 'unexpected_failure';
}
