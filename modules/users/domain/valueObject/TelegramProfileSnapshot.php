<?php

declare(strict_types=1);

namespace modules\users\domain\valueObject;

use modules\users\domain\exception\InvalidTelegramProfileSnapshotException;

final class TelegramProfileSnapshot
{
    private function __construct(
        private readonly ?string $username,
        private readonly ?string $firstName,
        private readonly ?string $lastName,
        private readonly ?string $languageCode,
    ) {
    }

    public static function create(
        ?string $username,
        ?string $firstName,
        ?string $lastName,
        ?string $languageCode,
    ): self {
        return new self(
            self::normalizeAndValidate($username, 'username', 64),
            self::normalizeAndValidate($firstName, 'first_name', 255),
            self::normalizeAndValidate($lastName, 'last_name', 255),
            self::normalizeAndValidate($languageCode, 'language_code', 16),
        );
    }

    public static function empty(): self
    {
        return new self(null, null, null, null);
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function firstName(): ?string
    {
        return $this->firstName;
    }

    public function lastName(): ?string
    {
        return $this->lastName;
    }

    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    public function isEmpty(): bool
    {
        return $this->username === null
            && $this->firstName === null
            && $this->lastName === null
            && $this->languageCode === null;
    }

    public function equals(self $other): bool
    {
        return $this->username === $other->username
            && $this->firstName === $other->firstName
            && $this->lastName === $other->lastName
            && $this->languageCode === $other->languageCode;
    }

    private static function normalizeAndValidate(?string $value, string $field, int $maximumLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidTelegramProfileSnapshotException($field . '_invalid_utf8');
        }

        if (mb_strlen($value, 'UTF-8') > $maximumLength) {
            throw new InvalidTelegramProfileSnapshotException($field . '_too_long');
        }

        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidTelegramProfileSnapshotException($field . '_control_character');
        }

        return $value;
    }
}
