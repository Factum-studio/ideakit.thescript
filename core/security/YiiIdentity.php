<?php

declare(strict_types=1);

namespace core\security;

use yii\web\IdentityInterface;

final class YiiIdentity implements IdentityInterface
{
    public function __construct(
    ) {
    }

    public function getId(): string
    {
        return;
        //TODO: impl getting id
    }

    public function getAuthKey(): ?string
    {
        return null;
    }

    public function validateAuthKey($authKey): bool
    {
        return false;
    }

    public static function findIdentity($id)
    {
        return null;
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }
    /**
     * TODO: impl getUser
     * @example
     * public function getUser(): User
     * {
     *      return $this->user;
     * }
     */

    /**
     * TODO: impl getJwtToken
     * @example
     * public function getJwtToken(): ?JwtToken
     * {
     *      return $this->jwtToken;
     * }
     */
}
