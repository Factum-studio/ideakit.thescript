<?php

declare(strict_types=1);

namespace tests\unit\modules\users\application\handler;

use Codeception\Test\Unit;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use modules\users\application\command\MarkTelegramProfileBlockedCommand;
use modules\users\application\dto\VersionedTelegramIdentityProfile;
use modules\users\application\enum\TelegramProfileBlockOutcome;
use modules\users\application\exception\TelegramIdentityProfileConcurrencyException;
use modules\users\application\exception\TelegramIdentityProfileNotFoundException;
use modules\users\application\exception\TelegramIdentityProfilePersistenceException;
use modules\users\application\exception\TelegramProfileBlockConcurrencyException;
use modules\users\application\handler\MarkTelegramProfileBlockedHandler;
use modules\users\application\port\ITelegramIdentityProfileRepository;
use modules\users\application\port\ITransactionRunner;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramBotStatus;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use Throwable;

final class MarkTelegramProfileBlockedHandlerTest extends Unit
{
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ad0';
    private const USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ad1';
    private const CORRELATION_ID = '01890f4d-3c2a-7f48-8c0b-123456789ad2';

    public function testMarksActiveProfileBlockedWithExpectedVersion(): void
    {
        $blockedAt = self::utc('2026-09-05 13:00:00');
        $repository = new BlockSequenceProfileRepository(self::versionedProfile(
            TelegramBotStatus::ACTIVE,
            self::utc('2026-09-05 12:00:00'),
            null,
            4,
        ));
        $transactionRunner = new BlockRecordingTransactionRunner();

        $result = self::handler($repository, $transactionRunner)->handle(self::command($blockedAt));

        self::assertSame(self::PROFILE_ID, $result->telegramIdentityProfileId);
        self::assertSame(TelegramBotStatus::BOT_BLOCKED, $result->telegramBotStatus);
        self::assertSame(self::CORRELATION_ID, $result->correlationId);
        self::assertSame(TelegramProfileBlockOutcome::BLOCKED, $result->outcome);
        self::assertSame($blockedAt, $result->blockedAt);
        self::assertSame(1, $repository->saveCalls);
        self::assertSame([4], $repository->expectedVersions);
        self::assertSame(1, $transactionRunner->runs);
        self::assertSame(0, $transactionRunner->rollbacks);
    }

    public function testReturnsAlreadyBlockedAndPreservesInitialTimeWithoutSave(): void
    {
        $initialBlockedAt = self::utc('2026-09-05 13:00:00');
        $repository = new BlockSequenceProfileRepository(self::versionedProfile(
            TelegramBotStatus::BOT_BLOCKED,
            self::utc('2026-09-05 12:00:00'),
            $initialBlockedAt,
            2,
        ));

        $result = self::handler(
            $repository,
            new BlockRecordingTransactionRunner(),
        )->handle(self::command(self::utc('2026-09-05 14:00:00')));

        self::assertSame(TelegramProfileBlockOutcome::ALREADY_BLOCKED, $result->outcome);
        self::assertSame(TelegramBotStatus::BOT_BLOCKED, $result->telegramBotStatus);
        self::assertSame($initialBlockedAt, $result->blockedAt);
        self::assertSame(0, $repository->saveCalls);
    }

    public function testIgnoresBlockOlderThanLastInteraction(): void
    {
        $repository = new BlockSequenceProfileRepository(self::versionedProfile(
            TelegramBotStatus::ACTIVE,
            self::utc('2026-09-05 13:00:00'),
            null,
            3,
        ));

        $result = self::handler(
            $repository,
            new BlockRecordingTransactionRunner(),
        )->handle(self::command(self::utc('2026-09-05 12:59:59')));

        self::assertSame(TelegramProfileBlockOutcome::STALE_IGNORED, $result->outcome);
        self::assertSame(TelegramBotStatus::ACTIVE, $result->telegramBotStatus);
        self::assertNull($result->blockedAt);
        self::assertSame(0, $repository->saveCalls);
    }

    public function testDoesNotBlockAnonymizedProfile(): void
    {
        $repository = new BlockSequenceProfileRepository(self::versionedProfile(
            TelegramBotStatus::ANONYMIZED,
            self::utc('2026-09-05 12:00:00'),
            null,
            5,
        ));

        $result = self::handler(
            $repository,
            new BlockRecordingTransactionRunner(),
        )->handle(self::command(self::utc('2026-09-05 13:00:00')));

        self::assertSame(TelegramProfileBlockOutcome::PROFILE_ANONYMIZED, $result->outcome);
        self::assertSame(TelegramBotStatus::ANONYMIZED, $result->telegramBotStatus);
        self::assertNull($result->blockedAt);
        self::assertSame(0, $repository->saveCalls);
    }

    public function testThrowsTypedNotFoundWithoutRetry(): void
    {
        $repository = new BlockSequenceProfileRepository(null);
        $transactionRunner = new BlockRecordingTransactionRunner();

        try {
            self::handler($repository, $transactionRunner)->handle(
                self::command(self::utc('2026-09-05 13:00:00')),
            );
            self::fail('Expected missing Telegram identity profile.');
        } catch (TelegramIdentityProfileNotFoundException $exception) {
            self::assertSame('telegram_identity_profile_not_found', $exception->getMessage());
        }

        self::assertSame(1, $repository->findCalls);
        self::assertSame(1, $transactionRunner->runs);
        self::assertSame(1, $transactionRunner->rollbacks);
    }

    public function testRetriesOptimisticLockConflictFromFreshState(): void
    {
        $repository = new BlockSequenceProfileRepository(
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::utc('2026-09-05 12:00:00'),
                null,
                1,
            ),
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::utc('2026-09-05 12:00:00'),
                null,
                2,
            ),
        );
        $repository->saveFailures[] = new TelegramIdentityProfileConcurrencyException(
            'telegram_identity_profile_concurrency_conflict',
        );
        $transactionRunner = new BlockRecordingTransactionRunner();

        $result = self::handler($repository, $transactionRunner)->handle(
            self::command(self::utc('2026-09-05 13:00:00')),
        );

        self::assertSame(TelegramProfileBlockOutcome::BLOCKED, $result->outcome);
        self::assertSame(2, $repository->findCalls);
        self::assertSame(2, $repository->saveCalls);
        self::assertSame([1, 2], $repository->expectedVersions);
        self::assertSame(2, $transactionRunner->runs);
        self::assertSame(1, $transactionRunner->rollbacks);
    }

    public function testReturnsSuccessOnThirdAttempt(): void
    {
        $repository = new BlockSequenceProfileRepository(
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::utc('2026-09-05 12:00:00'),
                null,
                1,
            ),
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::utc('2026-09-05 12:00:00'),
                null,
                2,
            ),
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::utc('2026-09-05 12:00:00'),
                null,
                3,
            ),
        );
        $repository->saveFailures = [
            new TelegramIdentityProfileConcurrencyException(
                'telegram_identity_profile_concurrency_conflict',
            ),
            new TelegramIdentityProfileConcurrencyException(
                'telegram_identity_profile_concurrency_conflict',
            ),
        ];
        $transactionRunner = new BlockRecordingTransactionRunner();

        $result = self::handler($repository, $transactionRunner)->handle(
            self::command(self::utc('2026-09-05 13:00:00')),
        );

        self::assertSame(TelegramProfileBlockOutcome::BLOCKED, $result->outcome);
        self::assertSame(3, $repository->findCalls);
        self::assertSame(3, $repository->saveCalls);
        self::assertSame(3, $transactionRunner->runs);
        self::assertSame(2, $transactionRunner->rollbacks);
    }

    public function testConvertsThirdConflictToRetryableApplicationException(): void
    {
        $lastConflict = new TelegramIdentityProfileConcurrencyException(
            'telegram_identity_profile_concurrency_conflict',
        );
        $repository = new BlockSequenceProfileRepository(
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::utc('2026-09-05 12:00:00'),
                null,
                1,
            ),
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::utc('2026-09-05 12:00:00'),
                null,
                2,
            ),
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::utc('2026-09-05 12:00:00'),
                null,
                3,
            ),
        );
        $repository->saveFailures = [
            new TelegramIdentityProfileConcurrencyException(
                'telegram_identity_profile_concurrency_conflict',
            ),
            new TelegramIdentityProfileConcurrencyException(
                'telegram_identity_profile_concurrency_conflict',
            ),
            $lastConflict,
        ];
        $transactionRunner = new BlockRecordingTransactionRunner();

        try {
            self::handler($repository, $transactionRunner)->handle(
                self::command(self::utc('2026-09-05 13:00:00')),
            );
            self::fail('Expected exhausted optimistic-lock attempts.');
        } catch (TelegramProfileBlockConcurrencyException $exception) {
            self::assertSame('telegram_profile_block_concurrency_conflict', $exception->getMessage());
            self::assertTrue($exception->retryable);
            self::assertSame($lastConflict, $exception->getPrevious());
        }

        self::assertSame(3, $repository->findCalls);
        self::assertSame(3, $repository->saveCalls);
        self::assertSame(3, $transactionRunner->runs);
        self::assertSame(3, $transactionRunner->rollbacks);
    }

    public function testDoesNotRetryUnknownPersistenceFailure(): void
    {
        $failure = new TelegramIdentityProfilePersistenceException(
            'telegram_identity_profile_persistence_failure',
        );
        $repository = new BlockSequenceProfileRepository($failure);
        $transactionRunner = new BlockRecordingTransactionRunner();

        try {
            self::handler($repository, $transactionRunner)->handle(
                self::command(self::utc('2026-09-05 13:00:00')),
            );
            self::fail('Expected profile persistence failure.');
        } catch (TelegramIdentityProfilePersistenceException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $repository->findCalls);
        self::assertSame(1, $transactionRunner->runs);
        self::assertSame(1, $transactionRunner->rollbacks);
    }

    private static function handler(
        ITelegramIdentityProfileRepository $repository,
        ITransactionRunner $transactionRunner,
    ): MarkTelegramProfileBlockedHandler {
        return new MarkTelegramProfileBlockedHandler($repository, $transactionRunner);
    }

    private static function command(DateTimeImmutable $blockedAt): MarkTelegramProfileBlockedCommand
    {
        return new MarkTelegramProfileBlockedCommand(
            self::PROFILE_ID,
            'BOT_BLOCKED_BY_USER',
            $blockedAt,
            self::CORRELATION_ID,
        );
    }

    private static function versionedProfile(
        TelegramBotStatus $status,
        DateTimeImmutable $lastSeenAt,
        ?DateTimeImmutable $blockedAt,
        int $lockVersion,
    ): VersionedTelegramIdentityProfile {
        return new VersionedTelegramIdentityProfile(
            TelegramIdentityProfile::restore(
                new TelegramIdentityProfileId(self::PROFILE_ID),
                new UserIdentityId(self::USER_IDENTITY_ID),
                $status === TelegramBotStatus::ANONYMIZED
                    ? TelegramProfileSnapshot::empty()
                    : TelegramProfileSnapshot::create('example_user', 'First', 'Last', 'en'),
                $status,
                self::utc('2026-09-05 11:00:00'),
                $lastSeenAt,
                $blockedAt,
            ),
            $lockVersion,
        );
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}

final class BlockSequenceProfileRepository implements ITelegramIdentityProfileRepository
{
    /**
     * @var list<VersionedTelegramIdentityProfile|Throwable|null>
     */
    private array $findResults;

    /**
     * @var list<Throwable>
     */
    public array $saveFailures = [];

    /**
     * @var list<int>
     */
    public array $expectedVersions = [];

    public int $findCalls = 0;
    public int $saveCalls = 0;

    public function __construct(VersionedTelegramIdentityProfile|Throwable|null ...$findResults)
    {
        $this->findResults = $findResults;
    }

    public function findById(
        TelegramIdentityProfileId $id,
    ): ?VersionedTelegramIdentityProfile {
        ++$this->findCalls;
        $result = array_shift($this->findResults);

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function findByUserIdentityId(
        UserIdentityId $userIdentityId,
    ): ?VersionedTelegramIdentityProfile {
        throw new LogicException('findByUserIdentityId is not used by profile blocking.');
    }

    public function add(
        TelegramIdentityProfile $profile,
    ): VersionedTelegramIdentityProfile {
        throw new LogicException('add is not used by profile blocking.');
    }

    public function save(
        TelegramIdentityProfile $profile,
        int $expectedLockVersion,
    ): VersionedTelegramIdentityProfile {
        ++$this->saveCalls;
        $this->expectedVersions[] = $expectedLockVersion;
        $failure = array_shift($this->saveFailures);

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return new VersionedTelegramIdentityProfile($profile, $expectedLockVersion + 1);
    }
}

final class BlockRecordingTransactionRunner implements ITransactionRunner
{
    public int $runs = 0;
    public int $rollbacks = 0;

    public function run(callable $operation): mixed
    {
        ++$this->runs;

        try {
            return $operation();
        } catch (Throwable $exception) {
            ++$this->rollbacks;

            throw $exception;
        }
    }
}
