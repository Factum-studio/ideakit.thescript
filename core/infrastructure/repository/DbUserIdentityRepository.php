<?php

declare(strict_types=1);

namespace core\infrastructure\repository;

use core\application\exception\UserIdentityConcurrencyException;
use core\application\exception\UserIdentityResolutionPersistenceException;
use core\application\port\IUserIdentityRepository;
use core\domain\entity\UserIdentity;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;
use core\infrastructure\persistence\UserIdentityAR;
use DateTimeImmutable;
use Throwable;
use yii\db\IntegrityException;
use yii\validators\UniqueValidator;

class DbUserIdentityRepository implements IUserIdentityRepository
{
    public function save(UserIdentity $identity): void
    {
        try {
            $ar = UserIdentityAR::findOne($identity->getId()->value());
            if (!$ar) {
                $ar = new UserIdentityAR();
                $ar->id = $identity->getId()->value();
            }
            $ar->user_id = $identity->getUserId()->value();
            $ar->provider = $identity->getProvider();
            $ar->provider_client_id = $identity->getProviderClientId();
            $ar->created_at = $identity->getCreatedAt()->format('Y-m-d H:i:s');

            $this->deferProviderClientUniquenessToDatabase($ar);
            if (!$ar->save()) {
                throw $this->validationFailure($ar);
            }
        } catch (UserIdentityResolutionPersistenceException $exception) {
            throw $exception;
        } catch (IntegrityException $exception) {
            if ($this->isProviderClientUniqueViolation($exception)) {
                throw new UserIdentityConcurrencyException(
                    'user_identity_concurrency_conflict',
                    0,
                    $exception,
                );
            }

            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        } catch (Throwable $exception) {
            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        }
    }

    public function findById(UserIdentityId $id): ?UserIdentity
    {
        try {
            $ar = UserIdentityAR::findOne($id->value());

            return $ar ? $this->hydrate($ar) : null;
        } catch (Throwable $exception) {
            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        }
    }

    public function findByProviderAndClientId(string $provider, string $clientId): ?UserIdentity
    {
        try {
            $ar = UserIdentityAR::findOne(['provider' => $provider, 'provider_client_id' => $clientId]);

            return $ar ? $this->hydrate($ar) : null;
        } catch (Throwable $exception) {
            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        }
    }

    /**
     * @return UserIdentity[]
     */
    public function findByUserId(UserId $userId): array
    {
        try {
            $ars = UserIdentityAR::findAll(['user_id' => $userId->value()]);

            return array_map([$this, 'hydrate'], $ars);
        } catch (Throwable $exception) {
            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        }
    }

    public function deleteByUserId(UserId $userId): void
    {
        try {
            UserIdentityAR::deleteAll(['user_id' => $userId->value()]);
        } catch (Throwable $exception) {
            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        }
    }

    private function hydrate(UserIdentityAR $ar): UserIdentity
    {
        return new UserIdentity(
            new UserIdentityId($ar->id),
            new UserId($ar->user_id),
            $ar->provider,
            $ar->provider_client_id,
            new DateTimeImmutable($ar->created_at),
        );
    }

    private function validationFailure(
        UserIdentityAR $record,
    ): UserIdentityResolutionPersistenceException {
        $fields = array_keys($record->getErrors());
        sort($fields, SORT_STRING);

        return new UserIdentityResolutionPersistenceException(
            'user_identity_validation_failed:' . implode(',', $fields),
        );
    }

    private function deferProviderClientUniquenessToDatabase(UserIdentityAR $record): void
    {
        $validators = $record->getValidators();

        foreach ($validators as $index => $validator) {
            if (
                $validator instanceof UniqueValidator
                && $validator->attributes === ['provider', 'provider_client_id']
                && $validator->targetAttribute === ['provider', 'provider_client_id']
            ) {
                unset($validators[$index]);
            }
        }
    }

    private function isProviderClientUniqueViolation(IntegrityException $exception): bool
    {
        if (($exception->errorInfo[0] ?? null) !== '23505') {
            return false;
        }

        $driverMessage = (string) ($exception->errorInfo[2] ?? '');

        return str_contains(
            $driverMessage,
            'constraint "idx-user_identity-provider-client"',
        );
    }
}
