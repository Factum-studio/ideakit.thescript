<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

use InvalidArgumentException;

final class TelegramSenderSnapshot
{
    public function __construct(
        public readonly string $userId,
        public readonly string $firstName,
        public readonly ?string $username,
        public readonly ?string $lastName,
        public readonly ?string $languageCode,
    ) {
        if ($userId === '') {
            throw new InvalidArgumentException('invalid_sender_user_id');
        }

        if ($firstName === '') {
            throw new InvalidArgumentException('invalid_sender_first_name');
        }
    }
}
