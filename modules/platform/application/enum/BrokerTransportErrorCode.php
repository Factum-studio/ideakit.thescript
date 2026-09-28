<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum BrokerTransportErrorCode: string
{
    case CONFIGURATION_INVALID = 'configuration_invalid';
    case TOPOLOGY_MISMATCH = 'topology_mismatch';
    case INVALID_ENVELOPE = 'invalid_envelope';
    case UNROUTABLE = 'unroutable';
    case NACKED = 'nacked';
    case CONFIRM_TIMEOUT = 'confirm_timeout';
    case CONNECTION_FAILURE = 'connection_failure';
    case DELIVERY_ALREADY_SETTLED = 'delivery_already_settled';
    case DELIVERY_UNAVAILABLE = 'delivery_unavailable';
}
