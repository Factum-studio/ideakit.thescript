<?php

declare(strict_types=1);

namespace core\application\command;

class ChangeUserStatusCommand
{
    public function __construct(
        public string $userId,
        public int $newStatus,
        public string $updatedBy,
    ) {
    }
}
