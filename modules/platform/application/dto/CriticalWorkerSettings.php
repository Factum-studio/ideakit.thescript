<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\exception\CriticalWorkerException;

final class CriticalWorkerSettings
{
    /** @throws CriticalWorkerException */
    public function __construct(
        public readonly int $handlerTimeoutSeconds,
        public readonly int $brokerOperationTimeoutSeconds,
        public readonly int $receiveTimeoutSeconds,
        public readonly int $memoryLimitMib,
        public readonly int $softMemoryLimitMib,
        public readonly int $shutdownTimeoutSeconds,
    ) {
        if ($handlerTimeoutSeconds < 1 || $handlerTimeoutSeconds > 30
            || $brokerOperationTimeoutSeconds < 1 || $brokerOperationTimeoutSeconds > 20
            || $receiveTimeoutSeconds < 1 || $receiveTimeoutSeconds > 5
            || $memoryLimitMib < 64 || $memoryLimitMib > 512
            || $softMemoryLimitMib < 32 || $softMemoryLimitMib >= $memoryLimitMib
            || $shutdownTimeoutSeconds < 1 || $shutdownTimeoutSeconds > 20
            || $shutdownTimeoutSeconds < max($handlerTimeoutSeconds, $brokerOperationTimeoutSeconds)
        ) {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID);
        }
    }
}
