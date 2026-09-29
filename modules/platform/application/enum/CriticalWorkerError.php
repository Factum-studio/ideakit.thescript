<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum CriticalWorkerError: string
{
    case CONFIGURATION_INVALID = 'configuration_invalid';
    case HANDLER_MISSING = 'handler_missing';
    case UNSUPPORTED_CONTRACT = 'unsupported_contract';
    case TRANSPORT_FAILURE = 'transport_failure';
    case HANDLER_FAILURE = 'handler_failure';
    case EXECUTION_SCOPE_DIRTY = 'execution_scope_dirty';
    case CLEANUP_FAILURE = 'cleanup_failure';
}
