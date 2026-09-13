<?php

declare(strict_types=1);

namespace modules\users\domain\valueObject;

use modules\users\domain\exception\InvalidTelegramUserIdException;

final class TelegramUserId
{
    private const POSTGRESQL_BIGINT_MAX = '9223372036854775807';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new InvalidTelegramUserIdException('non_canonical');
        }

        $maximumLength = strlen(self::POSTGRESQL_BIGINT_MAX);
        $valueLength = strlen($value);

        if (
            $valueLength > $maximumLength
            || ($valueLength === $maximumLength && strcmp($value, self::POSTGRESQL_BIGINT_MAX) > 0)
        ) {
            throw new InvalidTelegramUserIdException('out_of_range');
        }

        return new self($value);
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
