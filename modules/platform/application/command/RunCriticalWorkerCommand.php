<?php

declare(strict_types=1);

namespace modules\platform\application\command;

use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\exception\CriticalWorkerException;

final class RunCriticalWorkerCommand
{
    /** @throws CriticalWorkerException */
    public function __construct(public readonly int $maxMessages, public readonly int $maxRuntimeSeconds)
    {
        if ($maxMessages < 1 || $maxMessages > 10000 || $maxRuntimeSeconds < 1 || $maxRuntimeSeconds > 3600) {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID);
        }
    }
}
