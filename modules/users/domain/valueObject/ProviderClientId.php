<?php

declare(strict_types=1);

namespace modules\users\domain\valueObject;

use modules\users\domain\exception\InvalidProviderClientId;

final class ProviderClientId
{
    private const MAX_LENGTH = 255;

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidProviderClientId('invalid_utf8');
        }

        if (preg_match('/^\s*$/u', $value) === 1) {
            throw new InvalidProviderClientId('empty');
        }

        if (mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidProviderClientId('too_long');
        }

        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidProviderClientId('control_character');
        }

        return new self($value);
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
