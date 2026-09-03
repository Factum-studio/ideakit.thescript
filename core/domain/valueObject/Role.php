<?php

declare(strict_types=1);

namespace core\domain\valueObject;

use InvalidArgumentException;

final class Role
{
    public const ROLE_USER      = 'user';
    public const ROLE_ADMIN     = 'admin';
    public const ROLE_MANAGER   = 'manager';

    private string $value;

    public function __construct(string $value)
    {
        if (!in_array($value, [self::ROLE_USER, self::ROLE_ADMIN, self::ROLE_MANAGER], true)) {
            throw new InvalidArgumentException('Invalid role');
        }
        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isAdmin(): bool
    {
        return $this->value === self::ROLE_ADMIN;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
