<?php

declare(strict_types=1);

namespace tests\unit\modules\users\application\handler;

use Codeception\Test\Unit;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use modules\users\application\command\ResolveTelegramIdentityCommand;
use modules\users\application\dto\ResolvedUserIdentityContext;
use modules\users\application\dto\VersionedTelegramIdentityProfile;
use modules\users\application\enum\TelegramIdentityResolutionOutcome;
use modules\users\application\enum\UserAccountStatus;
use modules\users\application\exception\TelegramIdentityProfileAlreadyExistsException;
use modules\users\application\exception\TelegramIdentityProfileConcurrencyException;
use modules\users\application\exception\TelegramIdentityProfilePersistenceException;
use modules\users\application\exception\TelegramIdentityResolutionConcurrencyException;
use modules\users\application\exception\UserIdentityResolutionConcurrencyException;
use modules\users\application\handler\ResolveTelegramIdentityHandler;
use modules\users\application\port\ITelegramIdentityProfileIdGenerator;
use modules\users\application\port\ITelegramIdentityProfileRepository;
use modules\users\application\port\ITransactionRunner;
use modules\users\application\port\IUserIdentityResolver;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramBotStatus;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use modules\users\domain\valueObject\TelegramUserId;
use Throwable;

final class ResolveTelegramIdentityHandlerTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac1';
    private const USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac2';
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac3';
    private const CORRELATION_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac4';

    /**
     * @dataProvider missingProfileOutcomes
     */
    public function testCreatesMissingProfile(
        bool $coreIdentityCreated,
        TelegramIdentityResolutionOutcome $expectedOutcome,
    ): void {
        $observedAt = self::utc('2026-09-05 12:00:00');
        $resolver = new SequenceUserIdentityResolver(
            self::context(UserAccountStatus::ACTIVE, $coreIdentityCreated),
        );
        $repository = new SequenceTelegramProfileRepository(null);
        $idGenerator = new FixedTelegramProfileIdGenerator(self::PROFILE_ID);
        $transactionRunner = new RecordingTransactionRunner();

        $result = self::handler(
            $resolver,
            $repository,
            $idGenerator,
            $transactionRunner,
        )->handle(self::command($observedAt));

        self::assertSame(self::USER_ID, $result->userId);
        self::assertSame(self::USER_IDENTITY_ID, $result->userIdentityId);
        self::assertSame(self::PROFILE_ID, $result->telegramIdentityProfileId);
        self::assertSame(UserAccountStatus::ACTIVE, $result->userStatus);
        self::assertSame(TelegramBotStatus::ACTIVE, $result->telegramBotStatus);
        self::assertSame(self::CORRELATION_ID, $result->correlationId);
        self::assertSame($expectedOutcome, $result->outcome);
        self::assertSame(1, $repository->addCalls);
        self::assertSame(0, $repository->saveCalls);
        self::assertSame(1, $idGenerator->calls);
        self::assertSame(['1000000000000000001'], $resolver->telegramUserIds);
        self::assertEquals([$observedAt], $resolver->resolvedAts);
        self::assertSame(1, $transactionRunner->runs);
        self::assertSame(0, $transactionRunner->rollbacks);
    }

    /**
     * @return iterable<string, array{bool, TelegramIdentityResolutionOutcome}>
     */
    public static function missingProfileOutcomes(): iterable
    {
        yield 'new Core identity' => [true, TelegramIdentityResolutionOutcome::CREATED];
        yield 'existing Core identity' => [false, TelegramIdentityResolutionOutcome::PROFILE_CREATED];
    }

    public function testUpdatesActiveProfileWithExpectedVersion(): void
    {
        $observedAt = self::utc('2026-09-05 12:10:00');
        $versionedProfile = self::versionedProfile(
            TelegramBotStatus::ACTIVE,
            self::snapshot('old_username'),
            self::utc('2026-09-05 12:00:00'),
            null,
            4,
        );
        $resolver = new SequenceUserIdentityResolver(self::context());
        $repository = new SequenceTelegramProfileRepository($versionedProfile);

        $result = self::handler(
            $resolver,
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            new RecordingTransactionRunner(),
        )->handle(self::command($observedAt, 'new_username'));

        self::assertSame(TelegramIdentityResolutionOutcome::UPDATED, $result->outcome);
        self::assertSame(TelegramBotStatus::ACTIVE, $result->telegramBotStatus);
        self::assertSame(1, $repository->saveCalls);
        self::assertSame([4], $repository->expectedVersions);
        self::assertSame('new_username', $repository->savedProfiles[0]->getProfileSnapshot()->username());
        self::assertSame($observedAt, $repository->savedProfiles[0]->getLastSeenAt());
    }

    public function testDoesNotSaveUnchangedActiveProfile(): void
    {
        $observedAt = self::utc('2026-09-05 12:00:00');
        $versionedProfile = self::versionedProfile(
            TelegramBotStatus::ACTIVE,
            self::snapshot('current_username'),
            $observedAt,
            null,
            2,
        );
        $repository = new SequenceTelegramProfileRepository($versionedProfile);

        $result = self::handler(
            new SequenceUserIdentityResolver(self::context()),
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            new RecordingTransactionRunner(),
        )->handle(self::command($observedAt, 'current_username'));

        self::assertSame(TelegramIdentityResolutionOutcome::UNCHANGED, $result->outcome);
        self::assertSame(0, $repository->addCalls);
        self::assertSame(0, $repository->saveCalls);
    }

    /**
     * @dataProvider staleProfiles
     */
    public function testIgnoresStaleInteraction(
        TelegramBotStatus $status,
        DateTimeImmutable $lastSeenAt,
        ?DateTimeImmutable $blockedAt,
        DateTimeImmutable $observedAt,
    ): void {
        $repository = new SequenceTelegramProfileRepository(self::versionedProfile(
            $status,
            self::snapshot('current_username'),
            $lastSeenAt,
            $blockedAt,
            3,
        ));

        $result = self::handler(
            new SequenceUserIdentityResolver(self::context()),
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            new RecordingTransactionRunner(),
        )->handle(self::command($observedAt, 'delayed_username'));

        self::assertSame(TelegramIdentityResolutionOutcome::STALE_IGNORED, $result->outcome);
        self::assertSame($status, $result->telegramBotStatus);
        self::assertSame(0, $repository->saveCalls);
    }

    /**
     * @return iterable<string, array{TelegramBotStatus, DateTimeImmutable, ?DateTimeImmutable, DateTimeImmutable}>
     */
    public static function staleProfiles(): iterable
    {
        yield 'before last seen' => [
            TelegramBotStatus::ACTIVE,
            self::utc('2026-09-05 12:00:00'),
            null,
            self::utc('2026-09-05 11:59:59'),
        ];
        yield 'before newer block' => [
            TelegramBotStatus::BOT_BLOCKED,
            self::utc('2026-09-05 12:00:00'),
            self::utc('2026-09-05 12:02:00'),
            self::utc('2026-09-05 12:01:00'),
        ];
    }

    public function testReactivatesBlockedProfileOnCurrentInteraction(): void
    {
        $observedAt = self::utc('2026-09-05 12:03:00');
        $repository = new SequenceTelegramProfileRepository(self::versionedProfile(
            TelegramBotStatus::BOT_BLOCKED,
            self::snapshot('old_username'),
            self::utc('2026-09-05 12:00:00'),
            self::utc('2026-09-05 12:02:00'),
            5,
        ));

        $result = self::handler(
            new SequenceUserIdentityResolver(self::context()),
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            new RecordingTransactionRunner(),
        )->handle(self::command($observedAt, 'new_username'));

        self::assertSame(TelegramIdentityResolutionOutcome::UPDATED, $result->outcome);
        self::assertSame(TelegramBotStatus::ACTIVE, $result->telegramBotStatus);
        self::assertSame(1, $repository->saveCalls);
        self::assertNull($repository->savedProfiles[0]->getBlockedAt());
    }

    public function testReturnsInactiveUserWithExistingProfileWithoutWrites(): void
    {
        $versionedProfile = self::versionedProfile(
            TelegramBotStatus::BOT_BLOCKED,
            self::snapshot('current_username'),
            self::utc('2026-09-05 12:00:00'),
            self::utc('2026-09-05 12:01:00'),
            1,
        );
        $repository = new SequenceTelegramProfileRepository($versionedProfile);

        $result = self::handler(
            new SequenceUserIdentityResolver(self::context(UserAccountStatus::INACTIVE)),
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            new RecordingTransactionRunner(),
        )->handle(self::command(self::utc('2026-09-05 12:02:00')));

        self::assertSame(TelegramIdentityResolutionOutcome::USER_INACTIVE, $result->outcome);
        self::assertSame(self::PROFILE_ID, $result->telegramIdentityProfileId);
        self::assertSame(TelegramBotStatus::BOT_BLOCKED, $result->telegramBotStatus);
        self::assertSame(0, $repository->addCalls);
        self::assertSame(0, $repository->saveCalls);
    }

    public function testReturnsInactiveUserWithoutCreatingMissingProfile(): void
    {
        $repository = new SequenceTelegramProfileRepository(null);
        $idGenerator = new FixedTelegramProfileIdGenerator(self::PROFILE_ID);

        $result = self::handler(
            new SequenceUserIdentityResolver(self::context(UserAccountStatus::INACTIVE)),
            $repository,
            $idGenerator,
            new RecordingTransactionRunner(),
        )->handle(self::command(self::utc('2026-09-05 12:00:00')));

        self::assertSame(TelegramIdentityResolutionOutcome::USER_INACTIVE, $result->outcome);
        self::assertNull($result->telegramIdentityProfileId);
        self::assertNull($result->telegramBotStatus);
        self::assertSame(0, $repository->addCalls);
        self::assertSame(0, $repository->saveCalls);
        self::assertSame(0, $idGenerator->calls);
    }

    public function testDoesNotRestoreAnonymizedProfile(): void
    {
        $repository = new SequenceTelegramProfileRepository(self::versionedProfile(
            TelegramBotStatus::ANONYMIZED,
            TelegramProfileSnapshot::empty(),
            self::utc('2026-09-05 12:00:00'),
            null,
            6,
        ));

        $result = self::handler(
            new SequenceUserIdentityResolver(self::context()),
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            new RecordingTransactionRunner(),
        )->handle(self::command(self::utc('2026-09-05 12:01:00')));

        self::assertSame(TelegramIdentityResolutionOutcome::PROFILE_ANONYMIZED, $result->outcome);
        self::assertSame(TelegramBotStatus::ANONYMIZED, $result->telegramBotStatus);
        self::assertSame(0, $repository->addCalls);
        self::assertSame(0, $repository->saveCalls);
    }

    public function testRetriesCoreIdentityConcurrencyFromFreshState(): void
    {
        $resolver = new SequenceUserIdentityResolver(
            new UserIdentityResolutionConcurrencyException('user_identity_concurrency_conflict'),
            self::context(UserAccountStatus::ACTIVE, true),
        );
        $repository = new SequenceTelegramProfileRepository(null);
        $transactionRunner = new RecordingTransactionRunner();

        $result = self::handler(
            $resolver,
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            $transactionRunner,
        )->handle(self::command(self::utc('2026-09-05 12:00:00')));

        self::assertSame(TelegramIdentityResolutionOutcome::CREATED, $result->outcome);
        self::assertSame(2, $resolver->calls);
        self::assertSame(1, $repository->findCalls);
        self::assertSame(2, $transactionRunner->runs);
        self::assertSame(1, $transactionRunner->rollbacks);
    }

    public function testRetriesProfileCreationConflictFromFreshState(): void
    {
        $observedAt = self::utc('2026-09-05 12:00:00');
        $resolver = new SequenceUserIdentityResolver(self::context(), self::context());
        $repository = new SequenceTelegramProfileRepository(
            null,
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::snapshot('current_username'),
                $observedAt,
                null,
                0,
            ),
        );
        $repository->addFailures[] = new TelegramIdentityProfileAlreadyExistsException(
            'telegram_identity_profile_already_exists',
        );
        $transactionRunner = new RecordingTransactionRunner();

        $result = self::handler(
            $resolver,
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            $transactionRunner,
        )->handle(self::command($observedAt, 'current_username'));

        self::assertSame(TelegramIdentityResolutionOutcome::UNCHANGED, $result->outcome);
        self::assertSame(2, $resolver->calls);
        self::assertSame(2, $repository->findCalls);
        self::assertSame(1, $repository->addCalls);
        self::assertSame(2, $transactionRunner->runs);
        self::assertSame(1, $transactionRunner->rollbacks);
    }

    public function testRetriesProfileUpdateConflictFromFreshState(): void
    {
        $resolver = new SequenceUserIdentityResolver(self::context(), self::context());
        $repository = new SequenceTelegramProfileRepository(
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::snapshot('old_username'),
                self::utc('2026-09-05 12:00:00'),
                null,
                2,
            ),
            self::versionedProfile(
                TelegramBotStatus::ACTIVE,
                self::snapshot('old_username'),
                self::utc('2026-09-05 12:00:00'),
                null,
                3,
            ),
        );
        $repository->saveFailures[] = new TelegramIdentityProfileConcurrencyException(
            'telegram_identity_profile_concurrency_conflict',
        );
        $transactionRunner = new RecordingTransactionRunner();

        $result = self::handler(
            $resolver,
            $repository,
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            $transactionRunner,
        )->handle(self::command(self::utc('2026-09-05 12:01:00'), 'new_username'));

        self::assertSame(TelegramIdentityResolutionOutcome::UPDATED, $result->outcome);
        self::assertSame(2, $resolver->calls);
        self::assertSame(2, $repository->findCalls);
        self::assertSame(2, $repository->saveCalls);
        self::assertSame([2, 3], $repository->expectedVersions);
        self::assertSame(2, $transactionRunner->runs);
        self::assertSame(1, $transactionRunner->rollbacks);
    }

    public function testReturnsSuccessOnThirdAttempt(): void
    {
        $resolver = new SequenceUserIdentityResolver(
            new UserIdentityResolutionConcurrencyException('user_identity_concurrency_conflict'),
            new UserIdentityResolutionConcurrencyException('user_identity_concurrency_conflict'),
            self::context(UserAccountStatus::ACTIVE, true),
        );
        $transactionRunner = new RecordingTransactionRunner();

        $result = self::handler(
            $resolver,
            new SequenceTelegramProfileRepository(null),
            new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
            $transactionRunner,
        )->handle(self::command(self::utc('2026-09-05 12:00:00')));

        self::assertSame(TelegramIdentityResolutionOutcome::CREATED, $result->outcome);
        self::assertSame(3, $resolver->calls);
        self::assertSame(3, $transactionRunner->runs);
        self::assertSame(2, $transactionRunner->rollbacks);
    }

    public function testConvertsThirdConflictToRetryableApplicationException(): void
    {
        $lastConflict = new UserIdentityResolutionConcurrencyException(
            'user_identity_concurrency_conflict',
        );
        $resolver = new SequenceUserIdentityResolver(
            new UserIdentityResolutionConcurrencyException('user_identity_concurrency_conflict'),
            new UserIdentityResolutionConcurrencyException('user_identity_concurrency_conflict'),
            $lastConflict,
        );
        $transactionRunner = new RecordingTransactionRunner();

        try {
            self::handler(
                $resolver,
                new SequenceTelegramProfileRepository(),
                new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
                $transactionRunner,
            )->handle(self::command(self::utc('2026-09-05 12:00:00')));
            self::fail('Expected exhausted concurrency attempts.');
        } catch (TelegramIdentityResolutionConcurrencyException $exception) {
            self::assertSame('telegram_identity_resolution_concurrency_conflict', $exception->getMessage());
            self::assertTrue($exception->retryable);
            self::assertSame($lastConflict, $exception->getPrevious());
        }

        self::assertSame(3, $resolver->calls);
        self::assertSame(3, $transactionRunner->runs);
        self::assertSame(3, $transactionRunner->rollbacks);
    }

    public function testDoesNotRetryUnknownPersistenceFailure(): void
    {
        $failure = new TelegramIdentityProfilePersistenceException(
            'telegram_identity_profile_persistence_failure',
        );
        $resolver = new SequenceUserIdentityResolver(self::context());
        $repository = new SequenceTelegramProfileRepository($failure);
        $transactionRunner = new RecordingTransactionRunner();

        try {
            self::handler(
                $resolver,
                $repository,
                new FixedTelegramProfileIdGenerator(self::PROFILE_ID),
                $transactionRunner,
            )->handle(self::command(self::utc('2026-09-05 12:00:00')));
            self::fail('Expected persistence failure.');
        } catch (TelegramIdentityProfilePersistenceException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $resolver->calls);
        self::assertSame(1, $repository->findCalls);
        self::assertSame(1, $transactionRunner->runs);
        self::assertSame(1, $transactionRunner->rollbacks);
    }

    private static function handler(
        IUserIdentityResolver $resolver,
        ITelegramIdentityProfileRepository $repository,
        ITelegramIdentityProfileIdGenerator $idGenerator,
        ITransactionRunner $transactionRunner,
    ): ResolveTelegramIdentityHandler {
        return new ResolveTelegramIdentityHandler(
            $resolver,
            $repository,
            $idGenerator,
            $transactionRunner,
        );
    }

    private static function command(
        DateTimeImmutable $observedAt,
        ?string $username = 'current_username',
    ): ResolveTelegramIdentityCommand {
        return new ResolveTelegramIdentityCommand(
            '1000000000000000001',
            $username,
            'First',
            'Last',
            'en',
            $observedAt,
            self::CORRELATION_ID,
        );
    }

    private static function context(
        UserAccountStatus $status = UserAccountStatus::ACTIVE,
        bool $created = false,
    ): ResolvedUserIdentityContext {
        return new ResolvedUserIdentityContext(
            self::USER_ID,
            self::USER_IDENTITY_ID,
            $status,
            $created,
        );
    }

    private static function versionedProfile(
        TelegramBotStatus $status,
        TelegramProfileSnapshot $snapshot,
        DateTimeImmutable $lastSeenAt,
        ?DateTimeImmutable $blockedAt,
        int $lockVersion,
    ): VersionedTelegramIdentityProfile {
        return new VersionedTelegramIdentityProfile(
            TelegramIdentityProfile::restore(
                new TelegramIdentityProfileId(self::PROFILE_ID),
                new UserIdentityId(self::USER_IDENTITY_ID),
                $snapshot,
                $status,
                self::utc('2026-09-05 11:00:00'),
                $lastSeenAt,
                $blockedAt,
            ),
            $lockVersion,
        );
    }

    private static function snapshot(string $username): TelegramProfileSnapshot
    {
        return TelegramProfileSnapshot::create($username, 'First', 'Last', 'en');
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}

final class SequenceUserIdentityResolver implements IUserIdentityResolver
{
    /**
     * @var list<ResolvedUserIdentityContext|Throwable>
     */
    private array $results;

    public int $calls = 0;

    /**
     * @var list<string>
     */
    public array $telegramUserIds = [];

    /**
     * @var list<DateTimeImmutable>
     */
    public array $resolvedAts = [];

    public function __construct(ResolvedUserIdentityContext|Throwable ...$results)
    {
        $this->results = $results;
    }

    public function resolve(
        TelegramUserId $telegramUserId,
        DateTimeImmutable $resolvedAt,
    ): ResolvedUserIdentityContext {
        ++$this->calls;
        $this->telegramUserIds[] = $telegramUserId->value();
        $this->resolvedAts[] = $resolvedAt;
        $result = array_shift($this->results);

        if ($result instanceof Throwable) {
            throw $result;
        }

        if (!$result instanceof ResolvedUserIdentityContext) {
            throw new LogicException('Missing fake identity resolution result.');
        }

        return $result;
    }
}

final class SequenceTelegramProfileRepository implements ITelegramIdentityProfileRepository
{
    /**
     * @var list<VersionedTelegramIdentityProfile|Throwable|null>
     */
    private array $findResults;

    /**
     * @var list<Throwable>
     */
    public array $addFailures = [];

    /**
     * @var list<Throwable>
     */
    public array $saveFailures = [];

    /**
     * @var list<int>
     */
    public array $expectedVersions = [];

    /**
     * @var list<TelegramIdentityProfile>
     */
    public array $savedProfiles = [];

    public int $findCalls = 0;
    public int $addCalls = 0;
    public int $saveCalls = 0;

    public function __construct(VersionedTelegramIdentityProfile|Throwable|null ...$findResults)
    {
        $this->findResults = $findResults;
    }

    public function findById(
        TelegramIdentityProfileId $id,
    ): ?VersionedTelegramIdentityProfile {
        throw new LogicException('findById is not used by identity resolution.');
    }

    public function findByUserIdentityId(
        UserIdentityId $userIdentityId,
    ): ?VersionedTelegramIdentityProfile {
        ++$this->findCalls;
        $result = array_shift($this->findResults);

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function add(
        TelegramIdentityProfile $profile,
    ): VersionedTelegramIdentityProfile {
        ++$this->addCalls;
        $failure = array_shift($this->addFailures);

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return new VersionedTelegramIdentityProfile($profile, 0);
    }

    public function save(
        TelegramIdentityProfile $profile,
        int $expectedLockVersion,
    ): VersionedTelegramIdentityProfile {
        ++$this->saveCalls;
        $this->expectedVersions[] = $expectedLockVersion;
        $this->savedProfiles[] = $profile;
        $failure = array_shift($this->saveFailures);

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return new VersionedTelegramIdentityProfile($profile, $expectedLockVersion + 1);
    }
}

final class FixedTelegramProfileIdGenerator implements ITelegramIdentityProfileIdGenerator
{
    public int $calls = 0;

    public function __construct(private readonly string $value)
    {
    }

    public function generate(): TelegramIdentityProfileId
    {
        ++$this->calls;

        return new TelegramIdentityProfileId($this->value);
    }
}

final class RecordingTransactionRunner implements ITransactionRunner
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
