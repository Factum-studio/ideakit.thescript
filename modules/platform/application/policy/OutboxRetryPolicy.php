<?php

declare(strict_types=1);

namespace modules\platform\application\policy;

use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\port\IRetryJitter;

final class OutboxRetryPolicy
{
    public function __construct(
        private readonly OutboxRelaySettings $settings,
        private readonly IRetryJitter $jitter,
    ) {
    }

    /** @throws OutboxRelayException */
    public function mapTransportFailure(BrokerTransportErrorCode $code): OutboxRelayError
    {
        return match ($code) {
            BrokerTransportErrorCode::INVALID_ENVELOPE => OutboxRelayError::INVALID_MESSAGE,
            BrokerTransportErrorCode::CONNECTION_FAILURE => OutboxRelayError::CONNECTION_FAILURE,
            BrokerTransportErrorCode::NACKED => OutboxRelayError::NACKED,
            BrokerTransportErrorCode::CONFIRM_TIMEOUT => OutboxRelayError::CONFIRM_TIMEOUT,
            BrokerTransportErrorCode::UNROUTABLE => OutboxRelayError::UNROUTABLE,
            BrokerTransportErrorCode::CONFIGURATION_INVALID => OutboxRelayError::CONFIGURATION_INVALID,
            BrokerTransportErrorCode::TOPOLOGY_MISMATCH => OutboxRelayError::TOPOLOGY_MISMATCH,
            default => throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE),
        };
    }

    /** @throws OutboxRelayException */
    public function decide(OutboxRelayError $error, int $attemptNumber, int $maxAttempts): OutboxRelayDecision
    {
        if ($attemptNumber < 1 || $maxAttempts < 1 || $maxAttempts > 10) {
            throw new OutboxRelayException(OutboxRelayError::CONFIGURATION_INVALID);
        }
        if (!in_array($error, [
            OutboxRelayError::CONNECTION_FAILURE, OutboxRelayError::NACKED, OutboxRelayError::CONFIRM_TIMEOUT,
        ], true) || $attemptNumber >= $maxAttempts) {
            return OutboxRelayDecision::failed($error);
        }

        $base = min($this->settings->retryMaxSeconds, $this->settings->retryBaseSeconds * 2 ** ($attemptNumber - 1));
        $spread = min(intdiv($base, 5), $this->settings->retryMaxSeconds - $base);
        $jitter = $this->jitter->between(0, $spread);
        if ($jitter < 0 || $jitter > $spread) {
            throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE);
        }

        return OutboxRelayDecision::retry($error, $base + $jitter);
    }
}
