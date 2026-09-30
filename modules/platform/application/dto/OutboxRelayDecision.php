<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;

final class OutboxRelayDecision
{
    private function __construct(
        public readonly string $status,
        public readonly ?OutboxRelayError $error,
        public readonly ?int $delaySeconds,
    ) {
    }

    public static function delivered(): self
    {
        return new self('DELIVERED', null, null);
    }

    /** @throws OutboxRelayException */
    public static function retry(OutboxRelayError $error, int $delaySeconds): self
    {
        if ($delaySeconds < 1 || $delaySeconds > 3600 || !in_array($error, [
            OutboxRelayError::CONNECTION_FAILURE, OutboxRelayError::NACKED, OutboxRelayError::CONFIRM_TIMEOUT,
            OutboxRelayError::LEASE_EXPIRED,
        ], true)) {
            throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE);
        }

        return new self('RETRY_SCHEDULED', $error, $delaySeconds);
    }

    /** @throws OutboxRelayException */
    public static function failed(OutboxRelayError $error): self
    {
        if (in_array($error, [
            OutboxRelayError::CONFIGURATION_INVALID, OutboxRelayError::TOPOLOGY_MISMATCH,
            OutboxRelayError::PERSISTENCE_FAILURE, OutboxRelayError::TRANSACTION_ALREADY_ACTIVE,
            OutboxRelayError::UNEXPECTED_FAILURE,
        ], true)) {
            throw new OutboxRelayException($error);
        }

        return new self('FAILED', $error, null);
    }
}
