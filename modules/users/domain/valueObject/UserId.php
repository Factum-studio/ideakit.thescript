<?php

declare(strict_types=1);

namespace modules\users\domain\valueObject;

final class UserId
{
    private function __construct(private readonly UuidV7 $value)
    {
    }

    public static function fromString(string $value): self
    {
        return new self(UuidV7::fromString($value));
    }

    public function toString(): string
    {
        return $this->value->toString();
    }

    public function equals(self $other): bool
    {
        return $this->value->equals($other->value);
    }
}
