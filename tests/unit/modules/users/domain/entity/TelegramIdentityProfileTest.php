<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\entity;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\exception\TelegramProfileStateViolation;
use modules\users\domain\valueObject\TelegramBotStatus;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use modules\users\domain\valueObject\UserIdentityId;

final class TelegramIdentityProfileTest extends Unit
{
    private const ID = '01890f4d-3c2a-7f48-8c0b-123456789abc';
    private const USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789abd';

    public function testCreatesActiveProfileAtFirstIncomingInteraction(): void
    {
        $id = TelegramIdentityProfileId::fromString(self::ID);
        $userIdentityId = UserIdentityId::fromString(self::USER_IDENTITY_ID);
        $snapshot = self::snapshot('first_username');
        $seenAt = self::utc('2026-08-15 07:00:00');

        $profile = TelegramIdentityProfile::create($id, $userIdentityId, $snapshot, $seenAt);

        self::assertSame($id, $profile->id());
        self::assertSame($userIdentityId, $profile->userIdentityId());
        self::assertSame($snapshot, $profile->profileSnapshot());
        self::assertSame(TelegramBotStatus::ACTIVE, $profile->botStatus());
        self::assertSame($seenAt, $profile->firstSeenAt());
        self::assertSame($seenAt, $profile->lastSeenAt());
        self::assertNull($profile->blockedAt());
        self::assertTrue($profile->canReceiveInitiatedMessages());
    }

    /**
     * @dataProvider validRestoredStates
     */
    public function testRestoresConsistentState(
        TelegramProfileSnapshot $snapshot,
        TelegramBotStatus $status,
        ?DateTimeImmutable $blockedAt,
        bool $canReceiveInitiatedMessages,
    ): void {
        $profile = TelegramIdentityProfile::restore(
            TelegramIdentityProfileId::fromString(self::ID),
            UserIdentityId::fromString(self::USER_IDENTITY_ID),
            $snapshot,
            $status,
            self::utc('2026-08-15 07:00:00'),
            self::utc('2026-08-15 08:00:00'),
            $blockedAt,
        );

        self::assertSame($snapshot, $profile->profileSnapshot());
        self::assertSame($status, $profile->botStatus());
        self::assertSame($blockedAt, $profile->blockedAt());
        self::assertSame($canReceiveInitiatedMessages, $profile->canReceiveInitiatedMessages());
    }

    /**
     * @return iterable<string, array{TelegramProfileSnapshot, TelegramBotStatus, ?DateTimeImmutable, bool}>
     */
    public static function validRestoredStates(): iterable
    {
        yield 'active' => [self::snapshot('active_user'), TelegramBotStatus::ACTIVE, null, true];
        yield 'bot blocked' => [
            self::snapshot('blocked_user'),
            TelegramBotStatus::BOT_BLOCKED,
            self::utc('2026-08-15 09:00:00'),
            false,
        ];
        yield 'anonymized' => [TelegramProfileSnapshot::empty(), TelegramBotStatus::ANONYMIZED, null, false];
    }

    public function testCreateRejectsNonUtcSeenAt(): void
    {
        $this->expectException(TelegramProfileStateViolation::class);
        $this->expectExceptionMessage('seen_at_must_be_utc');

        TelegramIdentityProfile::create(
            TelegramIdentityProfileId::fromString(self::ID),
            UserIdentityId::fromString(self::USER_IDENTITY_ID),
            self::snapshot('username'),
            self::nonUtc('2026-08-15 12:00:00'),
        );
    }

    /**
     * @dataProvider invalidRestoredStates
     */
    public function testRestoreRejectsInconsistentState(
        TelegramProfileSnapshot $snapshot,
        TelegramBotStatus $status,
        DateTimeImmutable $firstSeenAt,
        DateTimeImmutable $lastSeenAt,
        ?DateTimeImmutable $blockedAt,
        string $reason,
    ): void {
        $this->expectException(TelegramProfileStateViolation::class);
        $this->expectExceptionMessage($reason);

        TelegramIdentityProfile::restore(
            TelegramIdentityProfileId::fromString(self::ID),
            UserIdentityId::fromString(self::USER_IDENTITY_ID),
            $snapshot,
            $status,
            $firstSeenAt,
            $lastSeenAt,
            $blockedAt,
        );
    }

    /**
     * @return iterable<string, array{
     *     TelegramProfileSnapshot,
     *     TelegramBotStatus,
     *     DateTimeImmutable,
     *     DateTimeImmutable,
     *     ?DateTimeImmutable,
     *     string
     * }>
     */
    public static function invalidRestoredStates(): iterable
    {
        $snapshot = self::snapshot('username');
        $firstSeenAt = self::utc('2026-08-15 07:00:00');
        $lastSeenAt = self::utc('2026-08-15 08:00:00');

        yield 'last seen before first seen' => [
            $snapshot,
            TelegramBotStatus::ACTIVE,
            $firstSeenAt,
            self::utc('2026-08-15 06:59:59'),
            null,
            'last_seen_before_first_seen',
        ];
        yield 'active with blocked at' => [
            $snapshot,
            TelegramBotStatus::ACTIVE,
            $firstSeenAt,
            $lastSeenAt,
            self::utc('2026-08-15 09:00:00'),
            'blocked_at_not_allowed',
        ];
        yield 'bot blocked without blocked at' => [
            $snapshot,
            TelegramBotStatus::BOT_BLOCKED,
            $firstSeenAt,
            $lastSeenAt,
            null,
            'blocked_at_required',
        ];
        yield 'blocked at before last seen' => [
            $snapshot,
            TelegramBotStatus::BOT_BLOCKED,
            $firstSeenAt,
            $lastSeenAt,
            self::utc('2026-08-15 07:59:59'),
            'blocked_at_before_last_seen',
        ];
        yield 'anonymized with profile data' => [
            $snapshot,
            TelegramBotStatus::ANONYMIZED,
            $firstSeenAt,
            $lastSeenAt,
            null,
            'anonymized_snapshot_must_be_empty',
        ];
        yield 'anonymized with blocked at' => [
            TelegramProfileSnapshot::empty(),
            TelegramBotStatus::ANONYMIZED,
            $firstSeenAt,
            $lastSeenAt,
            self::utc('2026-08-15 09:00:00'),
            'blocked_at_not_allowed',
        ];
        yield 'first seen not UTC' => [
            $snapshot,
            TelegramBotStatus::ACTIVE,
            self::nonUtc('2026-08-15 12:00:00'),
            $lastSeenAt,
            null,
            'first_seen_at_must_be_utc',
        ];
        yield 'last seen not UTC' => [
            $snapshot,
            TelegramBotStatus::ACTIVE,
            $firstSeenAt,
            self::nonUtc('2026-08-15 13:00:00'),
            null,
            'last_seen_at_must_be_utc',
        ];
        yield 'blocked at not UTC' => [
            $snapshot,
            TelegramBotStatus::BOT_BLOCKED,
            $firstSeenAt,
            $lastSeenAt,
            self::nonUtc('2026-08-15 14:00:00'),
            'blocked_at_must_be_utc',
        ];
    }

    /**
     * @dataProvider profilesAcceptingIncomingInteraction
     */
    public function testRecordsIncomingInteractionAndReactivatesProfile(
        TelegramBotStatus $initialStatus,
        ?DateTimeImmutable $blockedAt,
    ): void {
        $firstSeenAt = self::utc('2026-08-15 07:00:00');
        $profile = TelegramIdentityProfile::restore(
            TelegramIdentityProfileId::fromString(self::ID),
            UserIdentityId::fromString(self::USER_IDENTITY_ID),
            self::snapshot('old_username'),
            $initialStatus,
            $firstSeenAt,
            self::utc('2026-08-15 08:00:00'),
            $blockedAt,
        );
        $newSnapshot = self::snapshot('new_username');
        $seenAt = self::utc('2026-08-15 10:00:00');

        $profile->recordIncomingInteraction($newSnapshot, $seenAt);

        self::assertSame($newSnapshot, $profile->profileSnapshot());
        self::assertSame(TelegramBotStatus::ACTIVE, $profile->botStatus());
        self::assertSame($firstSeenAt, $profile->firstSeenAt());
        self::assertSame($seenAt, $profile->lastSeenAt());
        self::assertNull($profile->blockedAt());
        self::assertTrue($profile->canReceiveInitiatedMessages());
    }

    /**
     * @return iterable<string, array{TelegramBotStatus, ?DateTimeImmutable}>
     */
    public static function profilesAcceptingIncomingInteraction(): iterable
    {
        yield 'active profile' => [TelegramBotStatus::ACTIVE, null];
        yield 'blocked profile' => [
            TelegramBotStatus::BOT_BLOCKED,
            self::utc('2026-08-15 09:00:00'),
        ];
    }

    /**
     * @dataProvider invalidIncomingInteractions
     */
    public function testRejectsInvalidIncomingInteraction(
        TelegramBotStatus $initialStatus,
        DateTimeImmutable $seenAt,
        string $reason,
    ): void {
        $profile = TelegramIdentityProfile::restore(
            TelegramIdentityProfileId::fromString(self::ID),
            UserIdentityId::fromString(self::USER_IDENTITY_ID),
            $initialStatus === TelegramBotStatus::ANONYMIZED
                ? TelegramProfileSnapshot::empty()
                : self::snapshot('old_username'),
            $initialStatus,
            self::utc('2026-08-15 07:00:00'),
            self::utc('2026-08-15 08:00:00'),
            null,
        );

        $this->expectException(TelegramProfileStateViolation::class);
        $this->expectExceptionMessage($reason);

        $profile->recordIncomingInteraction(self::snapshot('new_username'), $seenAt);
    }

    /**
     * @return iterable<string, array{TelegramBotStatus, DateTimeImmutable, string}>
     */
    public static function invalidIncomingInteractions(): iterable
    {
        yield 'seen before last seen' => [
            TelegramBotStatus::ACTIVE,
            self::utc('2026-08-15 07:59:59'),
            'seen_at_before_last_seen',
        ];
        yield 'interaction after anonymization' => [
            TelegramBotStatus::ANONYMIZED,
            self::utc('2026-08-15 09:00:00'),
            'interaction_after_anonymization',
        ];
        yield 'seen not UTC' => [
            TelegramBotStatus::ACTIVE,
            self::nonUtc('2026-08-15 14:00:00'),
            'seen_at_must_be_utc',
        ];
    }

    public function testMarksBotBlockedIdempotentlyAndPreservesInitialBlockedAt(): void
    {
        $snapshot = self::snapshot('username');
        $seenAt = self::utc('2026-08-15 08:00:00');
        $profile = TelegramIdentityProfile::create(
            TelegramIdentityProfileId::fromString(self::ID),
            UserIdentityId::fromString(self::USER_IDENTITY_ID),
            $snapshot,
            $seenAt,
        );
        $initialBlockedAt = self::utc('2026-08-15 09:00:00');

        $profile->markBotBlocked($initialBlockedAt);
        $profile->markBotBlocked(self::utc('2026-08-15 10:00:00'));

        self::assertSame($snapshot, $profile->profileSnapshot());
        self::assertSame(TelegramBotStatus::BOT_BLOCKED, $profile->botStatus());
        self::assertSame($seenAt, $profile->lastSeenAt());
        self::assertSame($initialBlockedAt, $profile->blockedAt());
        self::assertFalse($profile->canReceiveInitiatedMessages());
    }

    /**
     * @dataProvider invalidBotBlocks
     */
    public function testRejectsInvalidBotBlock(
        TelegramBotStatus $initialStatus,
        DateTimeImmutable $blockedAt,
        string $reason,
    ): void {
        $profile = TelegramIdentityProfile::restore(
            TelegramIdentityProfileId::fromString(self::ID),
            UserIdentityId::fromString(self::USER_IDENTITY_ID),
            $initialStatus === TelegramBotStatus::ANONYMIZED
                ? TelegramProfileSnapshot::empty()
                : self::snapshot('username'),
            $initialStatus,
            self::utc('2026-08-15 07:00:00'),
            self::utc('2026-08-15 08:00:00'),
            null,
        );

        $this->expectException(TelegramProfileStateViolation::class);
        $this->expectExceptionMessage($reason);

        $profile->markBotBlocked($blockedAt);
    }

    /**
     * @return iterable<string, array{TelegramBotStatus, DateTimeImmutable, string}>
     */
    public static function invalidBotBlocks(): iterable
    {
        yield 'blocked before last seen' => [
            TelegramBotStatus::ACTIVE,
            self::utc('2026-08-15 07:59:59'),
            'blocked_at_before_last_seen',
        ];
        yield 'block after anonymization' => [
            TelegramBotStatus::ANONYMIZED,
            self::utc('2026-08-15 09:00:00'),
            'block_after_anonymization',
        ];
        yield 'blocked at not UTC' => [
            TelegramBotStatus::ACTIVE,
            self::nonUtc('2026-08-15 14:00:00'),
            'blocked_at_must_be_utc',
        ];
    }

    public function testAnonymizesIdempotentlyAndPreservesIdentityAndHistory(): void
    {
        $id = TelegramIdentityProfileId::fromString(self::ID);
        $userIdentityId = UserIdentityId::fromString(self::USER_IDENTITY_ID);
        $firstSeenAt = self::utc('2026-08-15 07:00:00');
        $lastSeenAt = self::utc('2026-08-15 08:00:00');
        $profile = TelegramIdentityProfile::restore(
            $id,
            $userIdentityId,
            self::snapshot('username'),
            TelegramBotStatus::BOT_BLOCKED,
            $firstSeenAt,
            $lastSeenAt,
            self::utc('2026-08-15 09:00:00'),
        );

        $profile->anonymize();
        $profile->anonymize();

        self::assertSame($id, $profile->id());
        self::assertSame($userIdentityId, $profile->userIdentityId());
        self::assertTrue($profile->profileSnapshot()->isEmpty());
        self::assertSame(TelegramBotStatus::ANONYMIZED, $profile->botStatus());
        self::assertSame($firstSeenAt, $profile->firstSeenAt());
        self::assertSame($lastSeenAt, $profile->lastSeenAt());
        self::assertNull($profile->blockedAt());
        self::assertFalse($profile->canReceiveInitiatedMessages());
    }

    private static function snapshot(string $username): TelegramProfileSnapshot
    {
        return TelegramProfileSnapshot::create($username, 'First', 'Last', 'ru');
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private static function nonUtc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('Asia/Yekaterinburg'));
    }
}
