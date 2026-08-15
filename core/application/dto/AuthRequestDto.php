<?php

declare(strict_types=1);

namespace core\application\dto;

class AuthRequestDto
{
    /**
     * @param array<string, mixed> $userData
     */
    public function __construct(
        public string $provider,
        public string $providerClientId,
        public array $userData = [], // surname, name, patronymic, email, phone, etc.
    ) {
    }
}
