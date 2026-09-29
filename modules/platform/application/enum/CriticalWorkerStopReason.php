<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum CriticalWorkerStopReason: string
{
    case MESSAGE_LIMIT = 'message_limit';
    case RUNTIME_LIMIT = 'runtime_limit';
    case MEMORY_LIMIT = 'memory_limit';
    case SIGNAL = 'signal';
}
