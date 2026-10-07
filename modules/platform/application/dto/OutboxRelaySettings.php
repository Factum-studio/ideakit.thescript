<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;

final class OutboxRelaySettings
{
    /** @throws OutboxRelayException */
    public function __construct(
        public readonly int $maxAttempts,
        public readonly int $leaseSeconds,
        public readonly int $retryBaseSeconds,
        public readonly int $retryMaxSeconds,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 10
            || $leaseSeconds < 60 || $leaseSeconds > 3600
            || $retryBaseSeconds < 1 || $retryBaseSeconds > 300
            || $retryMaxSeconds < $retryBaseSeconds || $retryMaxSeconds > 3600
        ) {
            throw new OutboxRelayException(OutboxRelayError::CONFIGURATION_INVALID);
        }
    }
}
