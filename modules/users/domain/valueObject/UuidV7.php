<?php

declare(strict_types=1);

namespace modules\users\domain\valueObject;

use InvalidArgumentException;

final class UuidV7
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalizedValue = strtolower($value);

        if (preg_match(self::PATTERN, $normalizedValue) !== 1) {
            throw new InvalidArgumentException('invalid_uuid_v7');
        }

        return new self($normalizedValue);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
