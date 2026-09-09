<?php

declare(strict_types=1);

namespace core\domain\entity;

use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;

class User
{
    private UserId $id;
    private ?string $surname;
    private ?string $name;
    private ?string $patronymic;
    private ?Email $email;
    private ?Phone $phone;
    private Role $role;
    private ?string $post;
    private UserStatus $status;
    private string $authKey;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $lastLoginAt;

    /**
     * @var UserIdentity[]
     */
    private array $identities = [];

    public function __construct(
        UserId $id,
        ?string $surname,
        ?string $name,
        ?string $patronymic,
        ?Email $email,
        ?Phone $phone,
        Role $role,
        ?string $post,
        UserStatus $status,
        string $authKey,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $lastLoginAt = null,
    ) {
        $this->id           = $id;
        $this->surname      = $surname;
        $this->name         = $name;
        $this->patronymic   = $patronymic;
        $this->email        = $email;
        $this->phone        = $phone;
        $this->role         = $role;
        $this->post         = $post;
        $this->status       = $status;
        $this->authKey      = $authKey;
        $this->createdAt    = $createdAt;
        $this->updatedAt    = $updatedAt;
        $this->lastLoginAt  = $lastLoginAt;
    }

    // Геттеры
    public function getId(): UserId
    {
        return $this->id;
    }
    public function getSurname(): ?string
    {
        return $this->surname;
    }
    public function getName(): ?string
    {
        return $this->name;
    }
    public function getPatronymic(): ?string
    {
        return $this->patronymic;
    }
    public function getEmail(): ?Email
    {
        return $this->email;
    }
    public function getPhone(): ?Phone
    {
        return $this->phone;
    }
    public function getRole(): Role
    {
        return $this->role;
    }
    public function getPost(): ?string
    {
        return $this->post;
    }
    public function getStatus(): UserStatus
    {
        return $this->status;
    }
    public function getAuthKey(): string
    {
        return $this->authKey;
    }
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /**
     * @return UserIdentity[]
     */
    public function getIdentities(): array
    {
        return $this->identities;
    }

    // Сеттеры
    public function updateProfile(?string $surname, ?string $name, ?string $patronymic, ?Email $email, ?Phone $phone, ?string $post): void
    {
        $this->surname  = $surname;
        $this->name     = $name;
        $this->patronymic = $patronymic;
        $this->email    = $email;
        $this->phone    = $phone;
        $this->post     = $post;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function changeRole(Role $role): void
    {
        $this->role         = $role;
        $this->updatedAt    = new DateTimeImmutable();
    }

    public function changeStatus(UserStatus $status): void
    {
        $this->status       = $status;
        $this->updatedAt    = new DateTimeImmutable();
    }

    public function updateLastLogin(): void
    {
        $this->lastLoginAt  = new DateTimeImmutable();
        $this->updatedAt    = new DateTimeImmutable();
    }

    public function addIdentity(UserIdentity $identity): void
    {
        foreach ($this->identities as $existing) {
            if ($existing->getProvider() === $identity->getProvider()
                && $existing->getProviderClientId() === $identity->getProviderClientId()
            ) {
                return;
            }
        }
        $this->identities[] = $identity;
    }

    public function changeAuthKey(string $newAuthKey): void
    {
        $this->authKey      = $newAuthKey;
        $this->updatedAt    = new DateTimeImmutable();
    }

    public function updatePost(?string $post): void
    {
        $this->post         = $post;
        $this->updatedAt    = new DateTimeImmutable();
    }
}
