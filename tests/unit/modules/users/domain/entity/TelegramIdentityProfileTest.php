<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\entity;

use Codeception\Test\Unit;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use DateTimeZone;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\exception\TelegramProfileStateViolationException;
use modules\users\domain\valueObject\TelegramBotStatus;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;

final class TelegramIdentityProfileTest extends Unit
{
    private const ID = '01890f4d-3c2a-7f48-8c0b-123456789abc';
    private const USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789abd';

    public function testCreatesActiveProfileAtFirstIncomingInteraction(): void
    {
        $id = new TelegramIdentityProfileId(self::ID);
        $userIdentityId = new UserIdentityId(self::USER_IDENTITY_ID);
        $snapshot = self::snapshot('first_username');
        $seenAt = self::utc('2026-08-15 07:00:00');

        $profile = TelegramIdentityProfile::create($id, $userIdentityId, $snapshot, $seenAt);

        self::assertSame($id, $profile->getId());
        self::assertSame($userIdentityId, $profile->getUserIdentityId());
        self::assertSame($snapshot, $profile->getProfileSnapshot());
        self::assertSame(TelegramBotStatus::ACTIVE, $profile->getBotStatus());
        self::assertSame($seenAt, $profile->getFirstSeenAt());
        self::assertSame($seenAt, $profile->getLastSeenAt());
        self::assertNull($profile->getBlockedAt());
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
            new TelegramIdentityProfileId(self::ID),
            new UserIdentityId(self::USER_IDENTITY_ID),
            $snapshot,
            $status,
            self::utc('2026-08-15 07:00:00'),
            self::utc('2026-08-15 08:00:00'),
            $blockedAt,
        );

        self::assertSame($snapshot, $profile->getProfileSnapshot());
        self::assertSame($status, $profile->getBotStatus());
        self::assertSame($blockedAt, $profile->getBlockedAt());
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
        $this->expectException(TelegramProfileStateViolationException::class);
        $this->expectExceptionMessage('seen_at_must_be_utc');

        TelegramIdentityProfile::create(
            new TelegramIdentityProfileId(self::ID),
            new UserIdentityId(self::USER_IDENTITY_ID),
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
        $this->expectException(TelegramProfileStateViolationException::class);
        $this->expectExceptionMessage($reason);

        TelegramIdentityProfile::restore(
            new TelegramIdentityProfileId(self::ID),
            new UserIdentityId(self::USER_IDENTITY_ID),
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
            new TelegramIdentityProfileId(self::ID),
            new UserIdentityId(self::USER_IDENTITY_ID),
            self::snapshot('old_username'),
            $initialStatus,
            $firstSeenAt,
            self::utc('2026-08-15 08:00:00'),
            $blockedAt,
        );
        $newSnapshot = self::snapshot('new_username');
        $seenAt = self::utc('2026-08-15 10:00:00');

        $profile->recordIncomingInteraction($newSnapshot, $seenAt);

        self::assertSame($newSnapshot, $profile->getProfileSnapshot());
        self::assertSame(TelegramBotStatus::ACTIVE, $profile->getBotStatus());
        self::assertSame($firstSeenAt, $profile->getFirstSeenAt());
        self::assertSame($seenAt, $profile->getLastSeenAt());
        self::assertNull($profile->getBlockedAt());
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
            new TelegramIdentityProfileId(self::ID),
            new UserIdentityId(self::USER_IDENTITY_ID),
            $initialStatus === TelegramBotStatus::ANONYMIZED
                ? TelegramProfileSnapshot::empty()
                : self::snapshot('old_username'),
            $initialStatus,
            self::utc('2026-08-15 07:00:00'),
            self::utc('2026-08-15 08:00:00'),
            null,
        );

        $this->expectException(TelegramProfileStateViolationException::class);
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
            new TelegramIdentityProfileId(self::ID),
            new UserIdentityId(self::USER_IDENTITY_ID),
            $snapshot,
            $seenAt,
        );
        $initialBlockedAt = self::utc('2026-08-15 09:00:00');

        $profile->markBotBlocked($initialBlockedAt);
        $profile->markBotBlocked(self::utc('2026-08-15 10:00:00'));

        self::assertSame($snapshot, $profile->getProfileSnapshot());
        self::assertSame(TelegramBotStatus::BOT_BLOCKED, $profile->getBotStatus());
        self::assertSame($seenAt, $profile->getLastSeenAt());
        self::assertSame($initialBlockedAt, $profile->getBlockedAt());
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
            new TelegramIdentityProfileId(self::ID),
            new UserIdentityId(self::USER_IDENTITY_ID),
            $initialStatus === TelegramBotStatus::ANONYMIZED
                ? TelegramProfileSnapshot::empty()
                : self::snapshot('username'),
            $initialStatus,
            self::utc('2026-08-15 07:00:00'),
            self::utc('2026-08-15 08:00:00'),
            null,
        );

        $this->expectException(TelegramProfileStateViolationException::class);
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
        $id = new TelegramIdentityProfileId(self::ID);
        $userIdentityId = new UserIdentityId(self::USER_IDENTITY_ID);
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

        self::assertSame($id, $profile->getId());
        self::assertSame($userIdentityId, $profile->getUserIdentityId());
        self::assertTrue($profile->getProfileSnapshot()->isEmpty());
        self::assertSame(TelegramBotStatus::ANONYMIZED, $profile->getBotStatus());
        self::assertSame($firstSeenAt, $profile->getFirstSeenAt());
        self::assertSame($lastSeenAt, $profile->getLastSeenAt());
        self::assertNull($profile->getBlockedAt());
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
