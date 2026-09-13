<?php

declare(strict_types=1);

namespace modules\users\application\handler;

use modules\users\application\command\MarkTelegramProfileBlockedCommand;
use modules\users\application\dto\TelegramProfileBlockResult;
use modules\users\application\enum\TelegramProfileBlockOutcome;
use modules\users\application\exception\TelegramIdentityProfileConcurrencyException;
use modules\users\application\exception\TelegramIdentityProfileNotFoundException;
use modules\users\application\exception\TelegramProfileBlockConcurrencyException;
use modules\users\application\port\ITelegramIdentityProfileRepository;
use modules\users\application\port\ITransactionRunner;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramBotStatus;

final class MarkTelegramProfileBlockedHandler
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ITelegramIdentityProfileRepository $profileRepository,
        private readonly ITransactionRunner $transactionRunner,
    ) {
    }

    public function handle(
        MarkTelegramProfileBlockedCommand $command,
    ): TelegramProfileBlockResult {
        $attempt = 0;

        do {
            ++$attempt;

            try {
                return $this->transactionRunner->run(
                    fn (): TelegramProfileBlockResult => $this->blockOnce($command),
                );
            } catch (TelegramIdentityProfileConcurrencyException $exception) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw TelegramProfileBlockConcurrencyException::from($exception);
                }
            }
        } while (true);
    }

    private function blockOnce(
        MarkTelegramProfileBlockedCommand $command,
    ): TelegramProfileBlockResult {
        $versionedProfile = $this->profileRepository->findById(
            $command->telegramIdentityProfileId,
        );

        if ($versionedProfile === null) {
            throw new TelegramIdentityProfileNotFoundException(
                'telegram_identity_profile_not_found',
            );
        }

        $profile = $versionedProfile->profile();

        if ($profile->getBotStatus() === TelegramBotStatus::ANONYMIZED) {
            return $this->result(
                $profile,
                $command,
                TelegramProfileBlockOutcome::PROFILE_ANONYMIZED,
            );
        }

        if ($profile->getBotStatus() === TelegramBotStatus::BOT_BLOCKED) {
            return $this->result(
                $profile,
                $command,
                TelegramProfileBlockOutcome::ALREADY_BLOCKED,
            );
        }

        if ($command->blockedAt < $profile->getLastSeenAt()) {
            return $this->result(
                $profile,
                $command,
                TelegramProfileBlockOutcome::STALE_IGNORED,
            );
        }

        $profile->markBotBlocked($command->blockedAt);
        $storedProfile = $this->profileRepository->save(
            $profile,
            $versionedProfile->lockVersion(),
        )->profile();

        return $this->result(
            $storedProfile,
            $command,
            TelegramProfileBlockOutcome::BLOCKED,
        );
    }

    private function result(
        TelegramIdentityProfile $profile,
        MarkTelegramProfileBlockedCommand $command,
        TelegramProfileBlockOutcome $outcome,
    ): TelegramProfileBlockResult {
        return new TelegramProfileBlockResult(
            $profile->getId()->value(),
            $profile->getBotStatus(),
            $command->correlationId,
            $outcome,
            $profile->getBlockedAt(),
        );
    }
}
