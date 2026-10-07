<?php

declare(strict_types=1);

namespace modules\platform\application\enum;

enum BackgroundCommandOutcome: string
{
    case COMPLETED = 'completed';
    case ALREADY_COMPLETED = 'already_completed';
}
