<?php

declare(strict_types=1);

namespace modules\users\infrastructure\repository;

use core\domain\valueObject\UserIdentityId;
use modules\users\application\dto\VersionedTelegramIdentityProfile;
use modules\users\application\exception\TelegramIdentityProfileAlreadyExistsException;
use modules\users\application\exception\TelegramIdentityProfileConcurrencyException;
use modules\users\application\exception\TelegramIdentityProfileNotFoundException;
use modules\users\application\exception\TelegramIdentityProfilePersistenceException;
use modules\users\application\port\ITelegramIdentityProfileRepository;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\infrastructure\mapper\TelegramIdentityProfileMapper;
use modules\users\infrastructure\persistence\TelegramIdentityProfileAR;
use Throwable;
use yii\db\Expression;
use yii\db\IntegrityException;
use yii\db\StaleObjectException;

final class DbTelegramIdentityProfileRepository implements ITelegramIdentityProfileRepository
{
    public function __construct(
        private readonly TelegramIdentityProfileMapper $mapper,
    ) {
    }

    public function findById(
        TelegramIdentityProfileId $id,
    ): ?VersionedTelegramIdentityProfile {
        try {
            $record = $this->findRecord(['id' => $id->value()]);

            return $record === null ? null : $this->versioned($record);
        } catch (Throwable $exception) {
            throw new TelegramIdentityProfilePersistenceException(
                'telegram_identity_profile_persistence_failure',
                0,
                $exception,
            );
        }
    }

    public function findByUserIdentityId(
        UserIdentityId $userIdentityId,
    ): ?VersionedTelegramIdentityProfile {
        try {
            $record = $this->findRecord(['user_identity_id' => $userIdentityId->value()]);

            return $record === null ? null : $this->versioned($record);
        } catch (Throwable $exception) {
            throw new TelegramIdentityProfilePersistenceException(
                'telegram_identity_profile_persistence_failure',
                0,
                $exception,
            );
        }
    }

    public function add(
        TelegramIdentityProfile $profile,
    ): VersionedTelegramIdentityProfile {
        try {
            $record = $this->mapper->toNewRecord($profile);
            $record->lock_version = 0;
            $record->setAttribute('created_at', new Expression('CURRENT_TIMESTAMP'));
            $record->setAttribute('updated_at', new Expression('CURRENT_TIMESTAMP'));

            if (!$record->save()) {
                throw $this->validationFailure($record);
            }

            return $this->versioned($record);
        } catch (TelegramIdentityProfilePersistenceException $exception) {
            throw $exception;
        } catch (IntegrityException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new TelegramIdentityProfileAlreadyExistsException(
                    'telegram_identity_profile_already_exists',
                    0,
                    $exception,
                );
            }

            throw new TelegramIdentityProfilePersistenceException(
                'telegram_identity_profile_persistence_failure',
                0,
                $exception,
            );
        } catch (Throwable $exception) {
            throw new TelegramIdentityProfilePersistenceException(
                'telegram_identity_profile_persistence_failure',
                0,
                $exception,
            );
        }
    }

    public function save(
        TelegramIdentityProfile $profile,
        int $expectedLockVersion,
    ): VersionedTelegramIdentityProfile {
        if ($expectedLockVersion < 0) {
            throw new TelegramIdentityProfileConcurrencyException(
                'invalid_telegram_profile_lock_version',
            );
        }

        try {
            $record = $this->findRecord(['id' => $profile->getId()->value()]);
            if ($record === null) {
                throw new TelegramIdentityProfileNotFoundException(
                    'telegram_identity_profile_not_found',
                );
            }

            if ((int) $record->lock_version !== $expectedLockVersion) {
                throw new TelegramIdentityProfileConcurrencyException(
                    'telegram_identity_profile_concurrency_conflict',
                );
            }

            $persistedProfile = $this->mapper->toDomain($record);
            if (
                strtolower($persistedProfile->getUserIdentityId()->value())
                    !== strtolower($profile->getUserIdentityId()->value())
                || $persistedProfile->getFirstSeenAt() != $profile->getFirstSeenAt()
            ) {
                throw new TelegramIdentityProfilePersistenceException(
                    'telegram_identity_profile_immutable_state_mismatch',
                );
            }

            $this->mapper->applyMutableStateToRecord($profile, $record);
            $record->setAttribute('updated_at', new Expression('CURRENT_TIMESTAMP'));

            if (!$record->save()) {
                throw $this->validationFailure($record);
            }

            return $this->versioned($record);
        } catch (
            TelegramIdentityProfileNotFoundException
            | TelegramIdentityProfileConcurrencyException
            | TelegramIdentityProfilePersistenceException $exception
        ) {
            throw $exception;
        } catch (StaleObjectException $exception) {
            throw new TelegramIdentityProfileConcurrencyException(
                'telegram_identity_profile_concurrency_conflict',
                0,
                $exception,
            );
        } catch (Throwable $exception) {
            throw new TelegramIdentityProfilePersistenceException(
                'telegram_identity_profile_persistence_failure',
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string, string> $condition
     */
    private function findRecord(array $condition): ?TelegramIdentityProfileAR
    {
        $record = TelegramIdentityProfileAR::find()
            ->where($condition)
            ->limit(1)
            ->one();

        return $record instanceof TelegramIdentityProfileAR ? $record : null;
    }

    private function versioned(
        TelegramIdentityProfileAR $record,
    ): VersionedTelegramIdentityProfile {
        return new VersionedTelegramIdentityProfile(
            $this->mapper->toDomain($record),
            (int) $record->lock_version,
        );
    }

    private function validationFailure(
        TelegramIdentityProfileAR $record,
    ): TelegramIdentityProfilePersistenceException {
        $fields = array_keys($record->getErrors());
        sort($fields, SORT_STRING);

        return new TelegramIdentityProfilePersistenceException(
            'telegram_identity_profile_validation_failed:' . implode(',', $fields),
        );
    }

    private function isUniqueViolation(IntegrityException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }
}
