<?php

declare(strict_types=1);

namespace core\domain\entity;

use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;

class UserIdentity
{
    private UserIdentityId $id;
    private UserId $userId;
    private string $provider;
    private string $providerClientId;
    private DateTimeImmutable $createdAt;

    public function __construct(
        UserIdentityId $id,
        UserId $userId,
        string $provider,
        string $providerClientId,
        DateTimeImmutable $createdAt,
    ) {
        $this->id               = $id;
        $this->userId           = $userId;
        $this->provider         = $provider;
        $this->providerClientId = $providerClientId;
        $this->createdAt        = $createdAt;
    }

    public function getId(): UserIdentityId
    {
        return $this->id;
    }
    public function getUserId(): UserId
    {
        return $this->userId;
    }
    public function getProvider(): string
    {
        return $this->provider;
    }
    public function getProviderClientId(): string
    {
        return $this->providerClientId;
    }
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
