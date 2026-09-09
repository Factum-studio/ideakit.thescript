<?php

declare(strict_types=1);

namespace tests\integration\modules\users\application;

use Codeception\Test\Unit;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use modules\users\application\command\MarkTelegramProfileBlockedCommand;
use modules\users\application\command\ResolveTelegramIdentityCommand;
use modules\users\application\dto\VersionedTelegramIdentityProfile;
use modules\users\application\enum\TelegramIdentityResolutionOutcome;
use modules\users\application\enum\TelegramProfileBlockOutcome;
use modules\users\application\enum\TelegramProfileBlockReason;
use modules\users\application\enum\UserAccountStatus;
use modules\users\application\exception\TelegramIdentityProfileConcurrencyException;
use modules\users\application\exception\TelegramIdentityProfilePersistenceException;
use modules\users\application\handler\MarkTelegramProfileBlockedHandler;
use modules\users\application\handler\ResolveTelegramIdentityHandler;
use modules\users\application\port\ITelegramIdentityProfileIdGenerator;
use modules\users\application\port\ITelegramIdentityProfileRepository;
use modules\users\application\port\ITransactionRunner;
use modules\users\application\port\IUserIdentityResolver;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramBotStatus;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use modules\users\infrastructure\mapper\TelegramIdentityProfileMapper;
use modules\users\infrastructure\repository\DbTelegramIdentityProfileRepository;
use RuntimeException;
use Throwable;
use Yii;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

final class TelegramIdentityApplicationTest extends Unit
{
    private const FIRST_RESOLVE_ID = '1000000000000000301';
    private const REPEAT_RESOLVE_ID = '1000000000000000302';
    private const ROLLBACK_ID = '1000000000000000303';
    private const INACTIVE_ID = '1000000000000000304';
    private const CONFLICT_ID = '1000000000000000305';
    private const PARALLEL_ID = '1000000000000000306';
    private const PRIMARY_KEY_FIRST_ID = '1000000000000000307';
    private const PRIMARY_KEY_SECOND_ID = '1000000000000000308';
    private const BLOCK_RESTORE_ID = '1000000000000000309';

    private const INACTIVE_USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789b10';
    private const INACTIVE_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789b11';
    private const PRIMARY_KEY_FIRST_USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789b12';
    private const PRIMARY_KEY_FIRST_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789b13';
    private const PRIMARY_KEY_SECOND_USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789b14';
    private const PRIMARY_KEY_SECOND_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789b15';
    private const PRIMARY_KEY_PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789b16';

    private Connection $db;

    protected function _before(): void
    {
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);

        $this->db = $db;
        $this->deleteTestRows();
    }

    protected function _after(): void
    {
        if (isset($this->db)) {
            $this->deleteTestRows();
        }
    }

    public function testFirstResolveCreatesOneCoreUserIdentityAndTelegramProfile(): void
    {
        $result = $this->handler()->handle($this->command(
            self::FIRST_RESOLVE_ID,
            '2026-09-05 15:00:00',
            '01890f4d-3c2a-7f48-8c0b-123456789c01',
        ));

        self::assertSame(TelegramIdentityResolutionOutcome::CREATED, $result->outcome);
        self::assertSame(UserAccountStatus::ACTIVE, $result->userStatus);
        self::assertSame(TelegramBotStatus::ACTIVE, $result->telegramBotStatus);
        self::assertNotNull($result->telegramIdentityProfileId);
        self::assertSame(
            ['users' => 1, 'identities' => 1, 'profiles' => 1],
            $this->stateCounts(self::FIRST_RESOLVE_ID),
        );
    }

    public function testRepeatedResolveDoesNotCreateDuplicates(): void
    {
        $command = $this->command(
            self::REPEAT_RESOLVE_ID,
            '2026-09-05 15:01:00',
            '01890f4d-3c2a-7f48-8c0b-123456789c02',
        );

        $first = $this->handler()->handle($command);
        $second = $this->handler()->handle($command);

        self::assertSame(TelegramIdentityResolutionOutcome::CREATED, $first->outcome);
        self::assertSame(TelegramIdentityResolutionOutcome::UNCHANGED, $second->outcome);
        self::assertSame($first->userId, $second->userId);
        self::assertSame($first->userIdentityId, $second->userIdentityId);
        self::assertSame($first->telegramIdentityProfileId, $second->telegramIdentityProfileId);
        self::assertSame(
            ['users' => 1, 'identities' => 1, 'profiles' => 1],
            $this->stateCounts(self::REPEAT_RESOLVE_ID),
        );
    }

    public function testProfilePersistenceFailureRollsBackNewCoreRecords(): void
    {
        $userCountBefore = (int) (new Query())->from('{{%user}}')->count('*', $this->db);
        $handler = new ResolveTelegramIdentityHandler(
            Yii::$container->get(IUserIdentityResolver::class),
            new FailingProfileRepository(),
            Yii::$container->get(ITelegramIdentityProfileIdGenerator::class),
            Yii::$container->get(ITransactionRunner::class),
        );

        try {
            $handler->handle($this->command(
                self::ROLLBACK_ID,
                '2026-09-05 15:02:00',
                '01890f4d-3c2a-7f48-8c0b-123456789c03',
            ));
            self::fail('Expected profile persistence failure.');
        } catch (TelegramIdentityProfilePersistenceException $exception) {
            self::assertSame('telegram_identity_profile_persistence_failure', $exception->getMessage());
        }

        self::assertSame($userCountBefore, (int) (new Query())->from('{{%user}}')->count('*', $this->db));
        self::assertSame(
            ['users' => 0, 'identities' => 0, 'profiles' => 0],
            $this->stateCounts(self::ROLLBACK_ID),
        );
    }

    public function testInactiveCoreUserDoesNotCreateOrChangeProfile(): void
    {
        $this->insertCoreIdentity(
            self::INACTIVE_USER_ID,
            self::INACTIVE_IDENTITY_ID,
            self::INACTIVE_ID,
            0,
        );

        $result = $this->handler()->handle($this->command(
            self::INACTIVE_ID,
            '2026-09-05 15:03:00',
            '01890f4d-3c2a-7f48-8c0b-123456789c04',
        ));

        self::assertSame(TelegramIdentityResolutionOutcome::USER_INACTIVE, $result->outcome);
        self::assertSame(UserAccountStatus::INACTIVE, $result->userStatus);
        self::assertNull($result->telegramIdentityProfileId);
        self::assertNull($result->telegramBotStatus);
        self::assertSame(
            ['users' => 1, 'identities' => 1, 'profiles' => 0],
            $this->stateCounts(self::INACTIVE_ID),
        );
    }

    public function testOptimisticConflictReloadsAndPreservesNewerProfileState(): void
    {
        $this->handler()->handle($this->command(
            self::CONFLICT_ID,
            '2026-09-05 15:04:00',
            '01890f4d-3c2a-7f48-8c0b-123456789c05',
        ));
        $repository = Yii::$container->get(ITelegramIdentityProfileRepository::class);
        self::assertInstanceOf(DbTelegramIdentityProfileRepository::class, $repository);

        $dbConfig = require dirname(__DIR__, 5) . '/config/test_db.php';
        unset($dbConfig['class']);
        $externalDb = new Connection($dbConfig);
        $externalDb->open();

        try {
            $conflictingRepository = new ConcurrentUpdateProfileRepository(
                $repository,
                $externalDb,
                self::utc('2026-09-05 15:06:00'),
            );
            $handler = new ResolveTelegramIdentityHandler(
                Yii::$container->get(IUserIdentityResolver::class),
                $conflictingRepository,
                Yii::$container->get(ITelegramIdentityProfileIdGenerator::class),
                Yii::$container->get(ITransactionRunner::class),
            );

            $result = $handler->handle($this->command(
                self::CONFLICT_ID,
                '2026-09-05 15:05:00',
                '01890f4d-3c2a-7f48-8c0b-123456789c06',
                'older_update',
            ));
        } finally {
            $externalDb->close();
        }

        self::assertSame(TelegramIdentityResolutionOutcome::STALE_IGNORED, $result->outcome);
        self::assertSame(1, $conflictingRepository->saveAttempts);

        $persisted = $repository->findByUserIdentityId(new UserIdentityId($result->userIdentityId));
        self::assertNotNull($persisted);
        self::assertSame('newer_update', $persisted->profile()->getProfileSnapshot()->username());
        self::assertSame(
            '2026-09-05 15:06:00.000000+00:00',
            $persisted->profile()->getLastSeenAt()->format('Y-m-d H:i:s.uP'),
        );
    }

    public function testConcurrentFirstResolvesLeaveOneConsistentRecordSet(): void
    {
        self::assertTrue(function_exists('proc_open'), 'proc_open is required for this integration test.');

        $results = $this->runConcurrentResolves(self::PARALLEL_ID);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes, SORT_STRING);

        self::assertSame(['CREATED', 'UNCHANGED'], $outcomes);
        self::assertSame($results[0]['userId'], $results[1]['userId']);
        self::assertSame($results[0]['userIdentityId'], $results[1]['userIdentityId']);
        self::assertSame($results[0]['profileId'], $results[1]['profileId']);
        self::assertSame(
            ['users' => 1, 'identities' => 1, 'profiles' => 1],
            $this->stateCounts(self::PARALLEL_ID),
        );
    }

    public function testPrimaryKeyConflictIsNotClassifiedAsProfileCreationRace(): void
    {
        $this->insertCoreIdentity(
            self::PRIMARY_KEY_FIRST_USER_ID,
            self::PRIMARY_KEY_FIRST_IDENTITY_ID,
            self::PRIMARY_KEY_FIRST_ID,
            1,
        );
        $this->insertCoreIdentity(
            self::PRIMARY_KEY_SECOND_USER_ID,
            self::PRIMARY_KEY_SECOND_IDENTITY_ID,
            self::PRIMARY_KEY_SECOND_ID,
            1,
        );
        $repository = new DbTelegramIdentityProfileRepository(
            new TelegramIdentityProfileMapper(),
        );
        $profileId = new TelegramIdentityProfileId(self::PRIMARY_KEY_PROFILE_ID);
        $repository->add(TelegramIdentityProfile::create(
            $profileId,
            new UserIdentityId(self::PRIMARY_KEY_FIRST_IDENTITY_ID),
            TelegramProfileSnapshot::empty(),
            self::utc('2026-09-05 15:07:00'),
        ));

        try {
            $repository->add(TelegramIdentityProfile::create(
                $profileId,
                new UserIdentityId(self::PRIMARY_KEY_SECOND_IDENTITY_ID),
                TelegramProfileSnapshot::empty(),
                self::utc('2026-09-05 15:07:00'),
            ));
            self::fail('Expected a primary-key persistence failure.');
        } catch (TelegramIdentityProfilePersistenceException $exception) {
            self::assertSame('telegram_identity_profile_persistence_failure', $exception->getMessage());
        }
    }

    public function testConfirmedBlockAndLaterIncomingInteractionRestoresProfile(): void
    {
        $resolved = $this->handler()->handle($this->command(
            self::BLOCK_RESTORE_ID,
            '2026-09-05 15:08:00',
            '01890f4d-3c2a-7f48-8c0b-123456789c07',
            'initial_username',
        ));
        $profileId = $resolved->telegramIdentityProfileId;
        self::assertNotNull($profileId);

        $blockedAt = self::utc('2026-09-05 15:09:00');
        $blocked = Yii::$container->get(MarkTelegramProfileBlockedHandler::class)->handle(
            new MarkTelegramProfileBlockedCommand(
                $profileId,
                TelegramProfileBlockReason::BOT_BLOCKED_BY_USER->value,
                $blockedAt,
                '01890f4d-3c2a-7f48-8c0b-123456789c08',
            ),
        );

        self::assertSame(TelegramProfileBlockOutcome::BLOCKED, $blocked->outcome);
        self::assertSame(TelegramBotStatus::BOT_BLOCKED, $blocked->telegramBotStatus);
        self::assertEquals($blockedAt, $blocked->blockedAt);

        $restored = $this->handler()->handle($this->command(
            self::BLOCK_RESTORE_ID,
            '2026-09-05 15:10:00',
            '01890f4d-3c2a-7f48-8c0b-123456789c09',
            'restored_username',
        ));

        self::assertSame(TelegramIdentityResolutionOutcome::UPDATED, $restored->outcome);
        self::assertSame(TelegramBotStatus::ACTIVE, $restored->telegramBotStatus);
        self::assertSame($profileId, $restored->telegramIdentityProfileId);

        $repository = Yii::$container->get(ITelegramIdentityProfileRepository::class);
        $persisted = $repository->findByUserIdentityId(new UserIdentityId($restored->userIdentityId));
        self::assertNotNull($persisted);
        self::assertSame(TelegramBotStatus::ACTIVE, $persisted->profile()->getBotStatus());
        self::assertNull($persisted->profile()->getBlockedAt());
        self::assertSame('restored_username', $persisted->profile()->getProfileSnapshot()->username());
        self::assertSame(
            ['users' => 1, 'identities' => 1, 'profiles' => 1],
            $this->stateCounts(self::BLOCK_RESTORE_ID),
        );
    }

    private function handler(): ResolveTelegramIdentityHandler
    {
        return Yii::$container->get(ResolveTelegramIdentityHandler::class);
    }

    private function command(
        string $telegramUserId,
        string $observedAt,
        string $correlationId,
        string $username = 'integration_user',
    ): ResolveTelegramIdentityCommand {
        return new ResolveTelegramIdentityCommand(
            $telegramUserId,
            $username,
            'Integration',
            'Test',
            'en',
            self::utc($observedAt),
            $correlationId,
        );
    }

    /**
     * @return array{users: int, identities: int, profiles: int}
     */
    private function stateCounts(string $telegramUserId): array
    {
        $identityRows = (new Query())
            ->select(['id', 'user_id'])
            ->from('{{%user_identity}}')
            ->where([
                'provider' => 'telegram',
                'provider_client_id' => $telegramUserId,
            ])
            ->all($this->db);
        $identityIds = array_column($identityRows, 'id');
        $userIds = array_values(array_unique(array_column($identityRows, 'user_id')));

        return [
            'users' => $userIds === []
                ? 0
                : (int) (new Query())->from('{{%user}}')->where(['id' => $userIds])->count('*', $this->db),
            'identities' => count($identityRows),
            'profiles' => $identityIds === []
                ? 0
                : (int) (new Query())
                    ->from('{{%telegram_identity_profiles}}')
                    ->where(['user_identity_id' => $identityIds])
                    ->count('*', $this->db),
        ];
    }

    private function insertCoreIdentity(
        string $userId,
        string $identityId,
        string $telegramUserId,
        int $status,
    ): void {
        $this->db->createCommand()->insert('{{%user}}', [
            'id' => $userId,
            'surname' => null,
            'name' => null,
            'status' => $status,
            'auth_key' => '0123456789abcdef0123456789abcdef',
        ])->execute();
        $this->db->createCommand()->insert('{{%user_identity}}', [
            'id' => $identityId,
            'user_id' => $userId,
            'provider' => 'telegram',
            'provider_client_id' => $telegramUserId,
        ])->execute();
    }

    /**
     * @return list<array{outcome: string, userId: string, userIdentityId: string, profileId: string|null}>
     */
    private function runConcurrentResolves(string $telegramUserId): array
    {
        $root = dirname(__DIR__, 5);
        $barrierDirectory = sys_get_temp_dir() . '/ideakit-telegram-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($barrierDirectory, 0700));
        $readyFiles = [$barrierDirectory . '/ready-1', $barrierDirectory . '/ready-2'];
        $goFile = $barrierDirectory . '/go';
        $correlationIds = [
            '01890f4d-3c2a-7f48-8c0b-123456789c07',
            '01890f4d-3c2a-7f48-8c0b-123456789c08',
        ];
        $processes = [];

        try {
            foreach ($readyFiles as $index => $readyFile) {
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        '-r',
                        self::concurrentResolveScript(),
                        $readyFile,
                        $goFile,
                        $telegramUserId,
                        '2026-09-05 15:08:00',
                        $correlationIds[$index],
                        $root,
                    ],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [
                    'process' => $process,
                    'stdout' => $pipes[1],
                    'stderr' => $pipes[2],
                ];
            }

            $this->waitForBarrier($readyFiles);
            self::assertNotFalse(file_put_contents($goFile, 'go', LOCK_EX));

            $results = [];
            foreach ($processes as $process) {
                $stdout = stream_get_contents($process['stdout']);
                $stderr = stream_get_contents($process['stderr']);
                fclose($process['stdout']);
                fclose($process['stderr']);
                $exitCode = proc_close($process['process']);

                self::assertSame(0, $exitCode, $stderr === '' ? 'Concurrent resolve failed.' : $stderr);
                $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $results[] = $decoded;
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process['process'])) {
                    proc_terminate($process['process']);
                    proc_close($process['process']);
                }
            }
            foreach ([...$readyFiles, $goFile] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($barrierDirectory)) {
                rmdir($barrierDirectory);
            }
        }
    }

    /**
     * @param list<string> $readyFiles
     */
    private function waitForBarrier(array $readyFiles): void
    {
        $deadline = microtime(true) + 10.0;

        do {
            clearstatcache();
            if (count(array_filter($readyFiles, 'is_file')) === count($readyFiles)) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('Concurrent resolve processes did not reach the start barrier.');
    }

    private static function concurrentResolveScript(): string
    {
        return <<<'PHP'
require $argv[6] . '/vendor/autoload.php';
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');
require $argv[6] . '/vendor/yiisoft/yii2/Yii.php';
$_SERVER['SCRIPT_FILENAME'] = $argv[6] . '/web/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$config = require $argv[6] . '/config/test.php';
$application = new yii\web\Application($config);
$db = Yii::$app->get('db');
$db->open();
if (file_put_contents($argv[1], 'ready', LOCK_EX) === false) {
    throw new RuntimeException('Unable to enter the start barrier.');
}
$deadline = microtime(true) + 10.0;
while (!is_file($argv[2])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Start barrier timed out.');
    }
    usleep(10_000);
    clearstatcache();
}
$handler = Yii::$container->get(modules\users\application\handler\ResolveTelegramIdentityHandler::class);
$result = $handler->handle(new modules\users\application\command\ResolveTelegramIdentityCommand(
    $argv[3],
    'parallel_user',
    'Parallel',
    'Test',
    'en',
    new DateTimeImmutable($argv[4], new DateTimeZone('UTC')),
    $argv[5],
));
echo json_encode([
    'outcome' => $result->outcome->value,
    'userId' => $result->userId,
    'userIdentityId' => $result->userIdentityId,
    'profileId' => $result->telegramIdentityProfileId,
], JSON_THROW_ON_ERROR);
PHP;
    }

    private function deleteTestRows(): void
    {
        $identityRows = (new Query())
            ->select(['id', 'user_id'])
            ->from('{{%user_identity}}')
            ->where([
                'provider' => 'telegram',
                'provider_client_id' => self::telegramUserIds(),
            ])
            ->all($this->db);
        $identityIds = array_column($identityRows, 'id');
        $userIds = array_values(array_unique(array_column($identityRows, 'user_id')));

        if ($identityIds !== []) {
            $this->db->createCommand()
                ->delete('{{%telegram_identity_profiles}}', ['user_identity_id' => $identityIds])
                ->execute();
            $this->db->createCommand()
                ->delete('{{%user_identity}}', ['id' => $identityIds])
                ->execute();
        }
        if ($userIds !== []) {
            $this->db->createCommand()
                ->delete('{{%user}}', ['id' => $userIds])
                ->execute();
        }
    }

    /**
     * @return list<string>
     */
    private static function telegramUserIds(): array
    {
        return [
            self::FIRST_RESOLVE_ID,
            self::REPEAT_RESOLVE_ID,
            self::ROLLBACK_ID,
            self::INACTIVE_ID,
            self::CONFLICT_ID,
            self::PARALLEL_ID,
            self::PRIMARY_KEY_FIRST_ID,
            self::PRIMARY_KEY_SECOND_ID,
            self::BLOCK_RESTORE_ID,
        ];
    }

    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

final class FailingProfileRepository implements ITelegramIdentityProfileRepository
{
    public function findById(
        TelegramIdentityProfileId $id,
    ): ?VersionedTelegramIdentityProfile {
        throw new LogicException('findById is not used by identity resolution.');
    }

    public function findByUserIdentityId(
        UserIdentityId $userIdentityId,
    ): ?VersionedTelegramIdentityProfile {
        return null;
    }

    public function add(
        TelegramIdentityProfile $profile,
    ): VersionedTelegramIdentityProfile {
        throw new TelegramIdentityProfilePersistenceException(
            'telegram_identity_profile_persistence_failure',
        );
    }

    public function save(
        TelegramIdentityProfile $profile,
        int $expectedLockVersion,
    ): VersionedTelegramIdentityProfile {
        throw new LogicException('save is not used for a missing profile.');
    }
}

final class ConcurrentUpdateProfileRepository implements ITelegramIdentityProfileRepository
{
    public int $saveAttempts = 0;

    public function __construct(
        private readonly ITelegramIdentityProfileRepository $repository,
        private readonly Connection $externalDb,
        private readonly DateTimeImmutable $newerSeenAt,
    ) {
    }

    public function findById(
        TelegramIdentityProfileId $id,
    ): ?VersionedTelegramIdentityProfile {
        return $this->repository->findById($id);
    }

    public function findByUserIdentityId(
        UserIdentityId $userIdentityId,
    ): ?VersionedTelegramIdentityProfile {
        return $this->repository->findByUserIdentityId($userIdentityId);
    }

    public function add(
        TelegramIdentityProfile $profile,
    ): VersionedTelegramIdentityProfile {
        return $this->repository->add($profile);
    }

    public function save(
        TelegramIdentityProfile $profile,
        int $expectedLockVersion,
    ): VersionedTelegramIdentityProfile {
        ++$this->saveAttempts;

        if ($this->saveAttempts === 1) {
            $affectedRows = $this->externalDb->createCommand()->update(
                '{{%telegram_identity_profiles}}',
                [
                    'username' => 'newer_update',
                    'first_name' => 'Newer',
                    'last_name' => 'Update',
                    'language_code' => 'en',
                    'bot_status' => TelegramBotStatus::ACTIVE->value,
                    'last_seen_at' => $this->newerSeenAt->format('Y-m-d H:i:s.uP'),
                    'blocked_at' => null,
                    'lock_version' => new Expression('lock_version + 1'),
                    'updated_at' => new Expression('CURRENT_TIMESTAMP'),
                ],
                ['id' => $profile->getId()->value()],
            )->execute();

            if ($affectedRows !== 1) {
                throw new RuntimeException('Concurrent test update did not affect one profile.');
            }

            throw new TelegramIdentityProfileConcurrencyException(
                'telegram_identity_profile_concurrency_conflict',
            );
        }

        return $this->repository->save($profile, $expectedLockVersion);
    }
}
