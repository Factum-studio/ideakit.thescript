<?php

declare(strict_types=1);

namespace core\domain\valueObject;

use InvalidArgumentException;

final class Phone
{
    private string $value;

    public function __construct(string $value)
    {
        if (!preg_match('/^\+?[0-9]{10,15}$/', $value)) {
            throw new InvalidArgumentException('Invalid phone number');
        }
        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
