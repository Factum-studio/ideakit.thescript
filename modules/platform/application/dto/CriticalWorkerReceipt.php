<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\CriticalWorkerStopReason;

final class CriticalWorkerReceipt
{
    public function __construct(
        public readonly int $received,
        public readonly int $completed,
        public readonly int $alreadyCompleted,
        public readonly int $rejected,
        public readonly CriticalWorkerStopReason $stopReason,
    ) {
    }
}
