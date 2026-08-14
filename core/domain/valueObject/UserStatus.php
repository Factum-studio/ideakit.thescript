<?php

declare(strict_types=1);

namespace core\domain\valueObject;

use InvalidArgumentException;

final class UserStatus
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;

    private int $value;

    public function __construct(int $value)
    {
        if (!in_array($value, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
            throw new InvalidArgumentException('Invalid user status');
        }
        $this->value = $value;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function isActive(): bool
    {
        return $this->value === self::STATUS_ACTIVE;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
