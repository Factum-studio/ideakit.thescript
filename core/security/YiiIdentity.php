<?php

declare(strict_types=1);

namespace core\security;

use core\application\port\IUserRepository;
use core\domain\valueObject\UserId;
use core\infrastructure\jwt\JwtManager;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\web\IdentityInterface;
use core\domain\entity\User;

final class YiiIdentity implements IdentityInterface
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): string
    {
        return $this->user->getId()->value();
    }

    public function getAuthKey(): ?string
    {
        return $this->user->getAuthKey();
    }

    public function validateAuthKey($authKey): bool
    {
        return $this->user->getAuthKey() === $authKey;
    }

    /**
     * @throws NotInstantiableException
     * @throws InvalidConfigException
     */
    public static function findIdentity($id): YiiIdentity|IdentityInterface|null
    {
        // Реализация через репозиторий – вызовем из DI
        /** @var IUserRepository $userRepo */
        $userRepo   = Yii::$container->get(IUserRepository::class);
        $user       = $userRepo->findById(new UserId($id));
        return $user ? new self($user) : null;
    }

    /**
     * @throws NotInstantiableException
     * @throws InvalidConfigException
     */
    public static function findIdentityByAccessToken($token, $type = null): YiiIdentity|IdentityInterface|null
    {
        /** @var JwtManager $jwtManager */
        $jwtManager = Yii::$container->get(JwtManager::class);
        $payload    = $jwtManager->validate($token);
        if (!$payload) {
            return null;
        }

        /** @var IUserRepository $userRepo */
        $userRepo   = Yii::$container->get(IUserRepository::class);
        $user       = $userRepo->findById(new UserId($payload->userId));
        if (!$user) {
            return null;
        }

        if ($payload->authKey && $user->getAuthKey() !== $payload->authKey) {
            return null;
        }

        return new self($user);
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
