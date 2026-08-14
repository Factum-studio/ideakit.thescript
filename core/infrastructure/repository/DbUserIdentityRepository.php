<?php

declare(strict_types=1);

namespace core\infrastructure\repository;

use core\application\port\IUserIdentityRepository;
use core\domain\entity\UserIdentity;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;
use core\infrastructure\persistence\UserIdentityAR;
use DateTimeImmutable;
use Exception;
use RuntimeException;
use yii\db\Exception as DbException;

class DbUserIdentityRepository implements IUserIdentityRepository
{
    /**
     * @throws DbException
     */
    public function save(UserIdentity $identity): void
    {
        $ar = UserIdentityAR::findOne($identity->getId()->value());
        $isNew = false;
        if (!$ar) {
            $ar             = new UserIdentityAR();
            $ar->id         = $identity->getId()->value();
            $ar->created_at = $identity->getCreatedAt()->format('Y-m-d H:i:s');
            $isNew          = true;
        }
        $ar->user_id            = $identity->getUserId()->value();
        $ar->provider           = $identity->getProvider();
        $ar->provider_client_id = $identity->getProviderClientId();
        $ar->created_at         = $identity->getCreatedAt()->format('Y-m-d H:i:s');

        if (!$ar->save()) {
            throw new RuntimeException('Failed to save user identity: ' . implode(', ', $ar->getErrors()));
        }
    }

    /**
     * @throws Exception
     */
    public function findById(UserIdentityId $id): ?UserIdentity
    {
        $ar = UserIdentityAR::findOne($id->value());
        return $ar ? $this->hydrate($ar) : null;
    }

    /**
     * @throws Exception
     */
    public function findByProviderAndClientId(string $provider, string $clientId): ?UserIdentity
    {
        $ar = UserIdentityAR::findOne(['provider' => $provider, 'provider_client_id' => $clientId]);
        return $ar ? $this->hydrate($ar) : null;
    }

    /**
     * @return UserIdentity[]
     */
    public function findByUserId(UserId $userId): array
    {
        $ars = UserIdentityAR::findAll(['user_id' => $userId->value()]);
        return array_map([$this, 'hydrate'], $ars);
    }

    public function deleteByUserId(UserId $userId): void
    {
        UserIdentityAR::deleteAll(['user_id' => $userId->value()]);
    }

    /**
     * @throws Exception
     */
    private function hydrate(UserIdentityAR $ar): UserIdentity
    {
        return new UserIdentity(
            new UserIdentityId($ar->id),
            new UserId($ar->user_id),
            $ar->provider,
            $ar->provider_client_id,
            new DateTimeImmutable($ar->created_at)
        );
    }
}
