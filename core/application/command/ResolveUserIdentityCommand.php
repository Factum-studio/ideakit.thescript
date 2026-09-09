<?php

declare(strict_types=1);

namespace core\application\command;

use DateTimeImmutable;
use InvalidArgumentException;

final class ResolveUserIdentityCommand
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerClientId,
        public readonly DateTimeImmutable $resolvedAt,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,49}\z/D', $provider) !== 1) {
            throw new InvalidArgumentException('invalid_identity_provider');
        }

        if (
            $providerClientId === ''
            || strlen($providerClientId) > 255
            || trim($providerClientId) !== $providerClientId
            || preg_match('/[\x00-\x1F\x7F]/', $providerClientId) === 1
        ) {
            throw new InvalidArgumentException('invalid_identity_subject');
        }

        if ($resolvedAt->getTimezone()->getName() !== 'UTC') {
            throw new InvalidArgumentException('resolved_at_must_be_utc');
        }
    }
}
