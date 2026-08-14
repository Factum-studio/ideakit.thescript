<?php

declare(strict_types=1);

namespace core\application\command;

class UpdateUserCommand
{
    public function __construct(
        public string $userId,
        public ?string $surname = null,
        public ?string $name    = null,
        public ?string $patronymic = null,
        public ?string $email   = null,
        public ?string $phone   = null,
        public ?string $role    = null,
        public ?string $post    = null,
        public ?int $status     = null,
    ) {
    }
}
