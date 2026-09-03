<?php

declare(strict_types=1);

namespace core\domain\valueObject;

use Ramsey\Uuid\Uuid;
use InvalidArgumentException;

final class UserId
{
    private string $value;

    public function __construct(string $value)
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidArgumentException('Invalid UUID format for UserId');
        }
        $this->value = $value;
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
