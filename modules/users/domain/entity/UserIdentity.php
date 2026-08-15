<?php

declare(strict_types=1);

namespace modules\users\domain\entity;

use DateTimeImmutable;
use InvalidArgumentException;
use modules\users\domain\valueObject\IdentityProvider;
use modules\users\domain\valueObject\ProviderClientId;
use modules\users\domain\valueObject\UserId;
use modules\users\domain\valueObject\UserIdentityId;

final class UserIdentity
{
    private function __construct(
        private readonly UserIdentityId $id,
        private readonly UserId $userId,
        private readonly IdentityProvider $provider,
        private ?ProviderClientId $providerClientId,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ($createdAt->getTimezone()->getName() !== 'UTC') {
            throw new InvalidArgumentException('created_at_must_be_utc');
        }
    }

    public static function create(
        UserIdentityId $id,
        UserId $userId,
        IdentityProvider $provider,
        ProviderClientId $providerClientId,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $userId, $provider, $providerClientId, $createdAt);
    }

    public static function restore(
        UserIdentityId $id,
        UserId $userId,
        IdentityProvider $provider,
        ?ProviderClientId $providerClientId,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $userId, $provider, $providerClientId, $createdAt);
    }

    public function anonymize(): void
    {
        $this->providerClientId = null;
    }

    public function isAnonymized(): bool
    {
        return $this->providerClientId === null;
    }

    public function belongsTo(UserId $userId): bool
    {
        return $this->userId->equals($userId);
    }

    public function hasProvider(IdentityProvider $provider): bool
    {
        return $this->provider === $provider;
    }

    public function id(): UserIdentityId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function provider(): IdentityProvider
    {
        return $this->provider;
    }

    public function providerClientId(): ?ProviderClientId
    {
        return $this->providerClientId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
