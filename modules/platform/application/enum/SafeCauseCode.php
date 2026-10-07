<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum SafeCauseCode: string
{
    case PERSISTENCE = 'PERSISTENCE';
    case HANDLER = 'HANDLER';
    case WORKER_RUNTIME = 'WORKER_RUNTIME';
    case TRANSPORT = 'TRANSPORT';
    case UNKNOWN = 'UNKNOWN';
}
