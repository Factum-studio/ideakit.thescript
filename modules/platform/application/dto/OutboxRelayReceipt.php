<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;

final class OutboxRelayReceipt
{
    /** @throws OutboxRelayException */
    public function __construct(
        public readonly int $claimed,
        public readonly int $delivered,
        public readonly int $retryScheduled,
        public readonly int $failed,
        public readonly int $leaseLost,
    ) {
        foreach ([$claimed, $delivered, $retryScheduled, $failed, $leaseLost] as $count) {
            if ($count < 0 || $count > 100) {
                throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE);
            }
        }
        if ($delivered + $retryScheduled + $failed + $leaseLost !== $claimed) {
            throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE);
        }
    }
}
