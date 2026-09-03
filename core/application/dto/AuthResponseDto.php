<?php

declare(strict_types=1);

namespace core\application\dto;

use JsonSerializable;

class AuthResponseDto implements JsonSerializable
{
    /**
     * @param array<string, mixed> $user
     */
    public function __construct(
        public string $accessToken,
        public array $user,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'access_token'  => $this->accessToken,
            'user'          => $this->user,
        ];
    }
}
