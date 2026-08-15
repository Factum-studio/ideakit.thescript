<?php

declare(strict_types=1);

namespace modules\users\domain\entity;

use DateTimeImmutable;
use modules\users\domain\exception\TelegramProfileStateViolation;
use modules\users\domain\valueObject\TelegramBotStatus;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use modules\users\domain\valueObject\UserIdentityId;

final class TelegramIdentityProfile
{
    private function __construct(
        private readonly TelegramIdentityProfileId $id,
        private readonly UserIdentityId $userIdentityId,
        private TelegramProfileSnapshot $profileSnapshot,
        private TelegramBotStatus $botStatus,
        private readonly DateTimeImmutable $firstSeenAt,
        private DateTimeImmutable $lastSeenAt,
        private ?DateTimeImmutable $blockedAt,
    ) {
        self::assertUtc($firstSeenAt, 'first_seen_at');
        self::assertUtc($lastSeenAt, 'last_seen_at');

        if ($blockedAt !== null) {
            self::assertUtc($blockedAt, 'blocked_at');
        }

        if ($lastSeenAt < $firstSeenAt) {
            throw new TelegramProfileStateViolation('last_seen_before_first_seen');
        }

        if ($botStatus === TelegramBotStatus::BOT_BLOCKED) {
            if ($blockedAt === null) {
                throw new TelegramProfileStateViolation('blocked_at_required');
            }

            if ($blockedAt < $lastSeenAt) {
                throw new TelegramProfileStateViolation('blocked_at_before_last_seen');
            }
        } elseif ($blockedAt !== null) {
            throw new TelegramProfileStateViolation('blocked_at_not_allowed');
        }

        if ($botStatus === TelegramBotStatus::ANONYMIZED && !$profileSnapshot->isEmpty()) {
            throw new TelegramProfileStateViolation('anonymized_snapshot_must_be_empty');
        }
    }

    public static function create(
        TelegramIdentityProfileId $id,
        UserIdentityId $userIdentityId,
        TelegramProfileSnapshot $profileSnapshot,
        DateTimeImmutable $seenAt,
    ): self {
        self::assertUtc($seenAt, 'seen_at');

        return new self(
            $id,
            $userIdentityId,
            $profileSnapshot,
            TelegramBotStatus::ACTIVE,
            $seenAt,
            $seenAt,
            null,
        );
    }

    public static function restore(
        TelegramIdentityProfileId $id,
        UserIdentityId $userIdentityId,
        TelegramProfileSnapshot $profileSnapshot,
        TelegramBotStatus $botStatus,
        DateTimeImmutable $firstSeenAt,
        DateTimeImmutable $lastSeenAt,
        ?DateTimeImmutable $blockedAt,
    ): self {
        return new self(
            $id,
            $userIdentityId,
            $profileSnapshot,
            $botStatus,
            $firstSeenAt,
            $lastSeenAt,
            $blockedAt,
        );
    }

    public function canReceiveInitiatedMessages(): bool
    {
        return $this->botStatus === TelegramBotStatus::ACTIVE;
    }

    public function recordIncomingInteraction(
        TelegramProfileSnapshot $profileSnapshot,
        DateTimeImmutable $seenAt,
    ): void {
        self::assertUtc($seenAt, 'seen_at');

        if ($this->botStatus === TelegramBotStatus::ANONYMIZED) {
            throw new TelegramProfileStateViolation('interaction_after_anonymization');
        }

        if ($seenAt < $this->lastSeenAt) {
            throw new TelegramProfileStateViolation('seen_at_before_last_seen');
        }

        $this->profileSnapshot = $profileSnapshot;
        $this->botStatus = TelegramBotStatus::ACTIVE;
        $this->lastSeenAt = $seenAt;
        $this->blockedAt = null;
    }

    public function markBotBlocked(DateTimeImmutable $blockedAt): void
    {
        if ($this->botStatus === TelegramBotStatus::BOT_BLOCKED) {
            return;
        }

        self::assertUtc($blockedAt, 'blocked_at');

        if ($this->botStatus === TelegramBotStatus::ANONYMIZED) {
            throw new TelegramProfileStateViolation('block_after_anonymization');
        }

        if ($blockedAt < $this->lastSeenAt) {
            throw new TelegramProfileStateViolation('blocked_at_before_last_seen');
        }

        $this->botStatus = TelegramBotStatus::BOT_BLOCKED;
        $this->blockedAt = $blockedAt;
    }

    public function anonymize(): void
    {
        if ($this->botStatus === TelegramBotStatus::ANONYMIZED) {
            return;
        }

        $this->profileSnapshot = TelegramProfileSnapshot::empty();
        $this->botStatus = TelegramBotStatus::ANONYMIZED;
        $this->blockedAt = null;
    }

    public function id(): TelegramIdentityProfileId
    {
        return $this->id;
    }

    public function userIdentityId(): UserIdentityId
    {
        return $this->userIdentityId;
    }

    public function profileSnapshot(): TelegramProfileSnapshot
    {
        return $this->profileSnapshot;
    }

    public function botStatus(): TelegramBotStatus
    {
        return $this->botStatus;
    }

    public function firstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function lastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function blockedAt(): ?DateTimeImmutable
    {
        return $this->blockedAt;
    }

    private static function assertUtc(DateTimeImmutable $time, string $field): void
    {
        if ($time->getTimezone()->getName() !== 'UTC') {
            throw new TelegramProfileStateViolation($field . '_must_be_utc');
        }
    }
}
