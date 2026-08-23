<?php

declare(strict_types=1);

namespace core\infrastructure\jwt;

use core\application\port\IJwtManager;
use core\domain\entity\User;
use core\application\dto\JwtPayloadDto;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

class JwtManager implements IJwtManager
{
    private string $secret;
    private int $ttl;

    public function __construct(string $secret, int $ttl = 3600)
    {
        $this->secret   = $secret;
        $this->ttl      = $ttl;
    }

    public function generate(User $user): string
    {
        $now    = time();
        $payload = new JwtPayloadDto(
            userId: $user->getId()->value(),
            role: $user->getRole()->value(),
            iat: $now,
            exp: $now + $this->ttl,
            authKey: $user->getAuthKey(),
        );

        return JWT::encode($payload->jsonSerialize(), $this->secret, 'HS256');
    }

    public function validate(string $token): ?JwtPayloadDto
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            $array = (array)$decoded;
            // Проверяем наличие обязательных полей
            if (!isset($array['sub']) || !isset($array['role']) || !isset($array['iat']) || !isset($array['exp'])) {
                return null;
            }
            return new JwtPayloadDto(
                userId: $array['sub'],
                role: $array['role'],
                iat: (int)$array['iat'],
                exp: (int)$array['exp'],
                authKey: $array['auth_key'] ?? null,
                extra: array_diff_key($array, array_flip(['sub', 'role', 'iat', 'exp', 'auth_key'])),
            );
        } catch (Throwable $e) {
            return null;
        }
    }
}
