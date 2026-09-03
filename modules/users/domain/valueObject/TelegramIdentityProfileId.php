<?php

declare(strict_types=1);

namespace modules\users\domain\valueObject;

use InvalidArgumentException;

final class TelegramIdentityProfileId
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $normalizedValue = strtolower($value);

        if (preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/', $normalizedValue) !== 1) {
            throw new InvalidArgumentException('invalid_telegram_identity_profile_id');
        }

        $this->value = $normalizedValue;
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
