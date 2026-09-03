<?php

declare(strict_types=1);

namespace core\infrastructure\jwt;

use core\application\dto\JwtPayloadDto;

class JwtValidator
{
    private JwtManager $jwtManager;

    public function __construct(JwtManager $jwtManager)
    {
        $this->jwtManager = $jwtManager;
    }

    public function validate(string $token): bool
    {
        return $this->jwtManager->validate($token) !== null;
    }

    public function getPayload(string $token): ?JwtPayloadDto
    {
        return $this->jwtManager->validate($token);
    }
}
