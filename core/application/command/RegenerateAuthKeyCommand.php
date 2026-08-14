<?php

declare(strict_types=1);

namespace core\application\command;

class RegenerateAuthKeyCommand
{
    public function __construct(
        public string $userId
    ) {
    }
}
