<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use DateTimeImmutable;
use DateTimeZone;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;
use Ramsey\Uuid\Uuid;

final class OutboxRelayClaim
{
    public readonly DateTimeImmutable $claimedAt;
    public readonly DateTimeImmutable $leaseUntil;

    /** @throws OutboxRelayException */
    public function __construct(
        public readonly string $outboxId,
        public readonly string $leaseToken,
        public readonly int $attemptNumber,
        public readonly bool $attemptStarted,
        DateTimeImmutable $claimedAt,
        DateTimeImmutable $leaseUntil,
        public readonly ?BrokerEnvelope $envelope,
        public readonly ?OutboxRelayError $rejection,
    ) {
        if (!Uuid::isValid($outboxId) || Uuid::fromString($outboxId)->toString() !== $outboxId
            || !mb_check_encoding($leaseToken, 'UTF-8') || trim($leaseToken) === ''
            || mb_strlen($leaseToken, 'UTF-8') > 128 || preg_match('/[\x00-\x1f\x7f]/', $leaseToken) !== 0
            || $attemptNumber < 1 || $leaseUntil <= $claimedAt
            || ($envelope === null) === ($rejection === null)
            || ($envelope !== null && $envelope->outboxId !== $outboxId)
            || ($rejection !== null && !in_array($rejection, [
                OutboxRelayError::INVALID_MESSAGE,
                OutboxRelayError::UNSUPPORTED_ROUTE,
                OutboxRelayError::ATTEMPT_LIMIT_REACHED,
            ], true))
            || (!$attemptStarted && $rejection !== OutboxRelayError::ATTEMPT_LIMIT_REACHED)
            || ($attemptStarted && $rejection === OutboxRelayError::ATTEMPT_LIMIT_REACHED)
        ) {
            throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
        }
        $utc = new DateTimeZone('UTC');
        $this->claimedAt = $claimedAt->setTimezone($utc);
        $this->leaseUntil = $leaseUntil->setTimezone($utc);
    }
}
