<?php

declare(strict_types=1);

namespace modules\users\infrastructure\mapper;

use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use DateTimeZone;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramBotStatus;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use modules\users\infrastructure\persistence\TelegramIdentityProfileAR;

final class TelegramIdentityProfileMapper
{
    public function toDomain(
        TelegramIdentityProfileAR $record,
    ): TelegramIdentityProfile {
        return TelegramIdentityProfile::restore(
            new TelegramIdentityProfileId($record->id),
            new UserIdentityId($record->user_identity_id),
            TelegramProfileSnapshot::create(
                $record->username,
                $record->first_name,
                $record->last_name,
                $record->language_code,
            ),
            TelegramBotStatus::from($record->bot_status),
            $this->utc($record->first_seen_at),
            $this->utc($record->last_seen_at),
            $record->blocked_at === null ? null : $this->utc($record->blocked_at),
        );
    }

    public function toNewRecord(
        TelegramIdentityProfile $profile,
    ): TelegramIdentityProfileAR {
        $record = new TelegramIdentityProfileAR();
        $record->id = $profile->getId()->value();
        $record->user_identity_id = $profile->getUserIdentityId()->value();
        $record->first_seen_at = $this->format($profile->getFirstSeenAt());
        $this->applyMutableStateToRecord($profile, $record);

        return $record;
    }

    public function applyMutableStateToRecord(
        TelegramIdentityProfile $profile,
        TelegramIdentityProfileAR $record,
    ): void {
        $snapshot = $profile->getProfileSnapshot();

        $record->username = $snapshot->username();
        $record->first_name = $snapshot->firstName();
        $record->last_name = $snapshot->lastName();
        $record->language_code = $snapshot->languageCode();
        $record->bot_status = $profile->getBotStatus()->value;
        $record->last_seen_at = $this->format($profile->getLastSeenAt());
        $record->blocked_at = $profile->getBlockedAt() === null
            ? null
            : $this->format($profile->getBlockedAt());
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }
}
