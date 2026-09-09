<?php

declare(strict_types=1);

namespace core\application\command;

class CreateUserCommand
{
    public function __construct(
        public ?string $surname,
        public ?string $name,
        public ?string $patronymic,
        public ?string $email,
        public ?string $phone,
        public string $role = 'user',
        public ?string $post = null,
        public int $status  = 1,
    ) {
    }
}
