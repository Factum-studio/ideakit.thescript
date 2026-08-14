<?php

declare(strict_types=1);

namespace core\application\command;

class DeleteUserCommand
{
    public function __construct(
        public string $userId
    ) {
    }
}
