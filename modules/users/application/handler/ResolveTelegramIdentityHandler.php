<?php

declare(strict_types=1);

namespace modules\users\application\handler;

use core\domain\valueObject\UserIdentityId;
use InvalidArgumentException;
use modules\users\application\command\ResolveTelegramIdentityCommand;
use modules\users\application\dto\ResolvedTelegramIdentity;
use modules\users\application\dto\ResolvedUserIdentityContext;
use modules\users\application\enum\TelegramIdentityResolutionOutcome;
use modules\users\application\enum\UserAccountStatus;
use modules\users\application\exception\TelegramIdentityProfileAlreadyExistsException;
use modules\users\application\exception\TelegramIdentityProfileConcurrencyException;
use modules\users\application\exception\TelegramIdentityResolutionConcurrencyException;
use modules\users\application\exception\UserIdentityResolutionConcurrencyException;
use modules\users\application\exception\UserIdentityResolutionIntegrityException;
use modules\users\application\port\ITelegramIdentityProfileIdGenerator;
use modules\users\application\port\ITelegramIdentityProfileRepository;
use modules\users\application\port\ITransactionRunner;
use modules\users\application\port\IUserIdentityResolver;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramBotStatus;

final class ResolveTelegramIdentityHandler
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly IUserIdentityResolver $identityResolver,
        private readonly ITelegramIdentityProfileRepository $profileRepository,
        private readonly ITelegramIdentityProfileIdGenerator $profileIdGenerator,
        private readonly ITransactionRunner $transactionRunner,
    ) {
    }

    public function handle(
        ResolveTelegramIdentityCommand $command,
    ): ResolvedTelegramIdentity {
        $attempt = 0;

        do {
            ++$attempt;

            try {
                return $this->transactionRunner->run(
                    fn (): ResolvedTelegramIdentity => $this->resolveOnce($command),
                );
            } catch (
                UserIdentityResolutionConcurrencyException
                | TelegramIdentityProfileAlreadyExistsException
                | TelegramIdentityProfileConcurrencyException $exception
            ) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw TelegramIdentityResolutionConcurrencyException::from($exception);
                }
            }
        } while (true);
    }

    private function resolveOnce(
        ResolveTelegramIdentityCommand $command,
    ): ResolvedTelegramIdentity {
        $identity = $this->identityResolver->resolve(
            $command->telegramUserId,
            $command->observedAt,
        );
        $userIdentityId = $this->userIdentityId($identity);
        $versionedProfile = $this->profileRepository->findByUserIdentityId($userIdentityId);

        if ($identity->userStatus === UserAccountStatus::INACTIVE) {
            return $this->result(
                $identity,
                $versionedProfile?->profile(),
                $command,
                TelegramIdentityResolutionOutcome::USER_INACTIVE,
            );
        }

        if ($versionedProfile === null) {
            $profile = TelegramIdentityProfile::create(
                $this->profileIdGenerator->generate(),
                $userIdentityId,
                $command->profileSnapshot,
                $command->observedAt,
            );
            $storedProfile = $this->profileRepository->add($profile)->profile();

            return $this->result(
                $identity,
                $storedProfile,
                $command,
                $identity->created
                    ? TelegramIdentityResolutionOutcome::CREATED
                    : TelegramIdentityResolutionOutcome::PROFILE_CREATED,
            );
        }

        $profile = $versionedProfile->profile();

        if ($profile->getBotStatus() === TelegramBotStatus::ANONYMIZED) {
            return $this->result(
                $identity,
                $profile,
                $command,
                TelegramIdentityResolutionOutcome::PROFILE_ANONYMIZED,
            );
        }

        if (
            $command->observedAt < $profile->getLastSeenAt()
            || (
                $profile->getBlockedAt() !== null
                && $command->observedAt < $profile->getBlockedAt()
            )
        ) {
            return $this->result(
                $identity,
                $profile,
                $command,
                TelegramIdentityResolutionOutcome::STALE_IGNORED,
            );
        }

        if (
            $profile->getBotStatus() === TelegramBotStatus::ACTIVE
            && $profile->getProfileSnapshot()->equals($command->profileSnapshot)
            && $profile->getLastSeenAt() == $command->observedAt
        ) {
            return $this->result(
                $identity,
                $profile,
                $command,
                TelegramIdentityResolutionOutcome::UNCHANGED,
            );
        }

        $profile->recordIncomingInteraction(
            $command->profileSnapshot,
            $command->observedAt,
        );
        $storedProfile = $this->profileRepository->save(
            $profile,
            $versionedProfile->lockVersion(),
        )->profile();

        return $this->result(
            $identity,
            $storedProfile,
            $command,
            TelegramIdentityResolutionOutcome::UPDATED,
        );
    }

    private function userIdentityId(
        ResolvedUserIdentityContext $identity,
    ): UserIdentityId {
        try {
            return new UserIdentityId($identity->userIdentityId);
        } catch (InvalidArgumentException $exception) {
            throw new UserIdentityResolutionIntegrityException(
                'invalid_resolved_user_identity_id',
                0,
                $exception,
            );
        }
    }

    private function result(
        ResolvedUserIdentityContext $identity,
        ?TelegramIdentityProfile $profile,
        ResolveTelegramIdentityCommand $command,
        TelegramIdentityResolutionOutcome $outcome,
    ): ResolvedTelegramIdentity {
        return new ResolvedTelegramIdentity(
            $identity->userId,
            $identity->userIdentityId,
            $profile?->getId()->value(),
            $identity->userStatus,
            $profile?->getBotStatus(),
            $command->correlationId,
            $outcome,
        );
    }
}
