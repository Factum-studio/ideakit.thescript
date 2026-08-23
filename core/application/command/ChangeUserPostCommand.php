<?php

declare(strict_types=1);

namespace core\application\command;

class ChangeUserPostCommand
{
    public function __construct(
        public string $userId,
        public ?string $newPost,
        public string $updatedBy,
    ) {
    }
}
