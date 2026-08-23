<?php

declare(strict_types=1);

namespace core\application\command;

class ChangeUserRoleCommand
{
    public function __construct(
        public string $userId,
        public string $newRole,
        public string $updatedBy,
    ) {
    }
}
