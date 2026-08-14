<?php

declare(strict_types=1);

namespace core\application\dto;

use JsonSerializable;

class JwtPayloadDto implements JsonSerializable
{
    public function __construct(
        public string $userId,
        public string $role,
        public int $iat,
        public int $exp,
        public ?string $authKey = null,
        public array $extra     = []
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'sub'   => $this->userId,
            'role'  => $this->role,
            'iat'   => $this->iat,
            'exp'   => $this->exp,
            'auth_key' => $this->authKey,
            ...$this->extra,
        ];
    }
}
