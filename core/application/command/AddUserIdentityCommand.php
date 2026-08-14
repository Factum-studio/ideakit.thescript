<?php

declare(strict_types=1);

namespace core\application\command;

class AddUserIdentityCommand
{
    public function __construct(
        public string $userId,
        public string $provider,
        public string $providerClientId,
    ) {
    }
}
