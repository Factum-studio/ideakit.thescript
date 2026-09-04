<?php

declare(strict_types=1);

namespace tests\integration\modules\users\infrastructure;

use Codeception\Test\Unit;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use DateTimeZone;
use modules\users\application\exception\TelegramIdentityProfileAlreadyExistsException;
use modules\users\application\exception\TelegramIdentityProfileConcurrencyException;
use modules\users\application\exception\TelegramIdentityProfileNotFoundException;
use modules\users\application\exception\TelegramIdentityProfilePersistenceException;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramBotStatus;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use modules\users\infrastructure\mapper\TelegramIdentityProfileMapper;
use modules\users\infrastructure\repository\DbTelegramIdentityProfileRepository;
use Yii;
use yii\db\Connection;
use yii\db\IntegrityException;

final class DbTelegramIdentityProfileRepositoryTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac0';
    private const USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac1';
    private const SECOND_USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac2';
    private const UNKNOWN_USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac3';
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac4';
    private const SECOND_PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac5';
    private const UNKNOWN_PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac6';

    private Connection $db;
    private DbTelegramIdentityProfileRepository $repository;

    protected function _before(): void
    {
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);

        $this->db = $db;
        $this->deleteTestRows();
        $this->insertCoreUser();
        $this->insertCoreIdentity(self::USER_IDENTITY_ID, '1000000000000000101');
        $this->repository = new DbTelegramIdentityProfileRepository(
            new TelegramIdentityProfileMapper(),
        );
    }

    protected function _after(): void
    {
        if (isset($this->db)) {
            $this->deleteTestRows();
        }
    }

    public function testAddsAndFindsActiveProfileByIdAndIdentityId(): void
    {
        $profile = $this->activeProfile();

        $added = $this->repository->add($profile);
        $byId = $this->repository->findById($profile->getId());
        $byIdentityId = $this->repository->findByUserIdentityId($profile->getUserIdentityId());

        self::assertSame(0, $added->lockVersion());
        $this->assertProfileEquals($profile, $added->profile());
        self::assertNotNull($byId);
        self::assertSame(0, $byId->lockVersion());
        $this->assertProfileEquals($profile, $byId->profile());
        self::assertNotNull($byIdentityId);
        self::assertSame(0, $byIdentityId->lockVersion());
        $this->assertProfileEquals($profile, $byIdentityId->profile());
    }

    public function testRoundTripsBlockedProfileWithUtcTimestamps(): void
    {
        $profile = $this->activeProfile();
        $profile->markBotBlocked($this->utc('2026-09-04 10:05:00.654321'));

        $this->repository->add($profile);
        $found = $this->repository->findById($profile->getId());

        self::assertNotNull($found);
        self::assertSame(0, $found->lockVersion());
        $this->assertProfileEquals($profile, $found->profile());
        self::assertSame('UTC', $found->profile()->getFirstSeenAt()->getTimezone()->getName());
        self::assertSame('UTC', $found->profile()->getLastSeenAt()->getTimezone()->getName());
        self::assertSame('UTC', $found->profile()->getBlockedAt()?->getTimezone()->getName());
    }

    public function testRoundTripsAnonymizedProfileWithNullSnapshot(): void
    {
        $profile = $this->activeProfile();
        $profile->anonymize();

        $this->repository->add($profile);
        $found = $this->repository->findById($profile->getId());

        self::assertNotNull($found);
        self::assertSame(0, $found->lockVersion());
        self::assertTrue($found->profile()->getProfileSnapshot()->isEmpty());
        $this->assertProfileEquals($profile, $found->profile());
    }

    public function testReturnsNullForUnknownProfile(): void
    {
        self::assertNull(
            $this->repository->findById(new TelegramIdentityProfileId(self::UNKNOWN_PROFILE_ID)),
        );
        self::assertNull(
            $this->repository->findByUserIdentityId(new UserIdentityId(self::UNKNOWN_USER_IDENTITY_ID)),
        );
    }

    public function testRejectsSecondProfileForSameIdentity(): void
    {
        $this->repository->add($this->activeProfile());

        try {
            $this->repository->add($this->activeProfile(self::SECOND_PROFILE_ID));
            self::fail('Expected duplicate profile to be rejected.');
        } catch (TelegramIdentityProfileAlreadyExistsException $exception) {
            self::assertSame('telegram_identity_profile_already_exists', $exception->getMessage());
            self::assertInstanceOf(IntegrityException::class, $exception->getPrevious());
        }
    }

    public function testSaveRejectsMissingProfile(): void
    {
        $profile = $this->activeProfile(self::UNKNOWN_PROFILE_ID);

        try {
            $this->repository->save($profile, 0);
            self::fail('Expected missing profile to be rejected.');
        } catch (TelegramIdentityProfileNotFoundException $exception) {
            self::assertSame('telegram_identity_profile_not_found', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testSaveRejectsNegativeExpectedVersion(): void
    {
        $profile = $this->activeProfile();
        $this->repository->add($profile);

        try {
            $this->repository->save($profile, -1);
            self::fail('Expected negative version to be rejected.');
        } catch (TelegramIdentityProfileConcurrencyException $exception) {
            self::assertSame('invalid_telegram_profile_lock_version', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @dataProvider immutableMismatchCases
     */
    public function testSaveRejectsMismatchedImmutableState(string $case): void
    {
        $profile = $this->activeProfile();
        $this->repository->add($profile);

        if ($case === 'user_identity_id') {
            $this->insertCoreIdentity(self::SECOND_USER_IDENTITY_ID, '1000000000000000102');
        }

        $changed = TelegramIdentityProfile::restore(
            $profile->getId(),
            new UserIdentityId(
                $case === 'user_identity_id' ? self::SECOND_USER_IDENTITY_ID : self::USER_IDENTITY_ID,
            ),
            $profile->getProfileSnapshot(),
            $profile->getBotStatus(),
            $case === 'first_seen_at'
                ? $this->utc('2026-09-04 09:59:00.123456')
                : $profile->getFirstSeenAt(),
            $profile->getLastSeenAt(),
            $profile->getBlockedAt(),
        );

        try {
            $this->repository->save($changed, 0);
            self::fail('Expected immutable state mismatch to be rejected.');
        } catch (TelegramIdentityProfilePersistenceException $exception) {
            self::assertSame('telegram_identity_profile_immutable_state_mismatch', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function immutableMismatchCases(): iterable
    {
        yield 'identity ID' => ['user_identity_id'];
        yield 'first seen time' => ['first_seen_at'];
    }

    public function testConcurrentSaveRejectsStaleVersion(): void
    {
        $profile = $this->activeProfile();
        $this->repository->add($profile);
        $first = $this->repository->findById($profile->getId());
        $second = $this->repository->findById($profile->getId());
        self::assertNotNull($first);
        self::assertNotNull($second);

        $firstSnapshot = TelegramProfileSnapshot::create('first_update', 'First', 'Update', 'en');
        $first->profile()->recordIncomingInteraction(
            $firstSnapshot,
            $this->utc('2026-09-04 10:01:00.123456'),
        );
        $saved = $this->repository->save($first->profile(), $first->lockVersion());
        self::assertSame(1, $saved->lockVersion());

        $second->profile()->recordIncomingInteraction(
            TelegramProfileSnapshot::create('second_update', 'Second', 'Update', 'en'),
            $this->utc('2026-09-04 10:02:00.123456'),
        );

        try {
            $this->repository->save($second->profile(), $second->lockVersion());
            self::fail('Expected stale profile to be rejected.');
        } catch (TelegramIdentityProfileConcurrencyException $exception) {
            self::assertSame('telegram_identity_profile_concurrency_conflict', $exception->getMessage());
        }

        $persisted = $this->repository->findById($profile->getId());
        self::assertNotNull($persisted);
        self::assertSame(1, $persisted->lockVersion());
        self::assertTrue($firstSnapshot->equals($persisted->profile()->getProfileSnapshot()));
        self::assertSame(
            '2026-09-04 10:01:00.123456+00:00',
            $persisted->profile()->getLastSeenAt()->format('Y-m-d H:i:s.uP'),
        );
    }

    public function testSavePreservesCatalogCheckpointColumns(): void
    {
        $profile = $this->activeProfile();
        $this->repository->add($profile);
        $this->db->createCommand()->update('{{%telegram_identity_profiles}}', [
            'catalog_exhausted_through_sequence_no' => 42,
            'catalog_exhausted_at' => '2026-09-04 10:02:00.000000+00:00',
            'catalog_notified_through_sequence_no' => 41,
        ], ['id' => self::PROFILE_ID])->execute();

        $loaded = $this->repository->findById($profile->getId());
        self::assertNotNull($loaded);
        $loaded->profile()->recordIncomingInteraction(
            TelegramProfileSnapshot::create('updated_profile', 'Updated', 'Profile', 'en'),
            $this->utc('2026-09-04 10:03:00.123456'),
        );
        $saved = $this->repository->save($loaded->profile(), $loaded->lockVersion());

        $row = $this->db->createCommand(
            <<<'SQL'
SELECT
    catalog_exhausted_through_sequence_no,
    catalog_exhausted_at,
    catalog_notified_through_sequence_no
FROM {{%telegram_identity_profiles}}
WHERE id = :id
SQL,
            [':id' => self::PROFILE_ID],
        )->queryOne();

        self::assertSame(1, $saved->lockVersion());
        self::assertIsArray($row);
        self::assertSame(42, (int) $row['catalog_exhausted_through_sequence_no']);
        self::assertSame(41, (int) $row['catalog_notified_through_sequence_no']);
        self::assertSame(
            '2026-09-04 10:02:00.000000+00:00',
            (new DateTimeImmutable((string) $row['catalog_exhausted_at']))->format('Y-m-d H:i:s.uP'),
        );
    }

    public function testForeignKeyRejectsUnknownIdentity(): void
    {
        $profile = $this->activeProfile(self::SECOND_PROFILE_ID, self::UNKNOWN_USER_IDENTITY_ID);

        try {
            $this->repository->add($profile);
            self::fail('Expected unknown identity to be rejected.');
        } catch (TelegramIdentityProfilePersistenceException $exception) {
            self::assertSame('telegram_identity_profile_persistence_failure', $exception->getMessage());
            self::assertInstanceOf(IntegrityException::class, $exception->getPrevious());
        }
    }

    /**
     * @dataProvider invalidPersistenceRows
     *
     * @param array<string, int|string|null> $overrides
     */
    public function testDatabaseRejectsInvalidStatusAndStateCombinations(array $overrides): void
    {
        $this->expectException(IntegrityException::class);

        $this->insertRawProfile($overrides);
    }

    /**
     * @return iterable<string, array{array<string, int|string|null>}>
     */
    public static function invalidPersistenceRows(): iterable
    {
        yield 'unknown status' => [['bot_status' => 'UNKNOWN']];
        yield 'active with blocked time' => [['blocked_at' => '2026-09-04 10:01:00.000000+00:00']];
    }

    public function testRestrictPreventsDeletingReferencedIdentity(): void
    {
        $this->repository->add($this->activeProfile());

        $this->expectException(IntegrityException::class);

        $this->db->createCommand()
            ->delete('{{%user_identity}}', ['id' => self::USER_IDENTITY_ID])
            ->execute();
    }

    private function activeProfile(
        string $profileId = self::PROFILE_ID,
        string $userIdentityId = self::USER_IDENTITY_ID,
    ): TelegramIdentityProfile {
        return TelegramIdentityProfile::create(
            new TelegramIdentityProfileId($profileId),
            new UserIdentityId($userIdentityId),
            TelegramProfileSnapshot::create('repository_test', 'Repository', 'Test', 'en'),
            $this->utc('2026-09-04 10:00:00.123456'),
        );
    }

    private function assertProfileEquals(
        TelegramIdentityProfile $expected,
        TelegramIdentityProfile $actual,
    ): void {
        self::assertTrue($expected->getId()->equals($actual->getId()));
        self::assertTrue($expected->getUserIdentityId()->equals($actual->getUserIdentityId()));
        self::assertTrue($expected->getProfileSnapshot()->equals($actual->getProfileSnapshot()));
        self::assertSame($expected->getBotStatus(), $actual->getBotStatus());
        self::assertSame(
            $expected->getFirstSeenAt()->format('Y-m-d H:i:s.uP'),
            $actual->getFirstSeenAt()->format('Y-m-d H:i:s.uP'),
        );
        self::assertSame(
            $expected->getLastSeenAt()->format('Y-m-d H:i:s.uP'),
            $actual->getLastSeenAt()->format('Y-m-d H:i:s.uP'),
        );
        self::assertSame(
            $expected->getBlockedAt()?->format('Y-m-d H:i:s.uP'),
            $actual->getBlockedAt()?->format('Y-m-d H:i:s.uP'),
        );
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function insertCoreUser(): void
    {
        $this->db->createCommand()->insert('{{%user}}', [
            'id' => self::USER_ID,
            'surname' => 'Repository',
            'name' => 'Test',
            'auth_key' => 'fedcba9876543210fedcba9876543210',
        ])->execute();
    }

    private function insertCoreIdentity(string $identityId, string $providerClientId): void
    {
        $this->db->createCommand()->insert('{{%user_identity}}', [
            'id' => $identityId,
            'user_id' => self::USER_ID,
            'provider' => 'telegram',
            'provider_client_id' => $providerClientId,
        ])->execute();
    }

    /**
     * @param array<string, int|string|null> $overrides
     */
    private function insertRawProfile(array $overrides): void
    {
        $row = [
            'id' => self::SECOND_PROFILE_ID,
            'user_identity_id' => self::USER_IDENTITY_ID,
            'username' => 'raw_profile',
            'first_name' => 'Raw',
            'last_name' => 'Profile',
            'language_code' => 'en',
            'bot_status' => TelegramBotStatus::ACTIVE->value,
            'first_seen_at' => '2026-09-04 10:00:00.000000+00:00',
            'last_seen_at' => '2026-09-04 10:00:00.000000+00:00',
            'blocked_at' => null,
        ];

        $this->db->createCommand()
            ->insert('{{%telegram_identity_profiles}}', array_replace($row, $overrides))
            ->execute();
    }

    private function deleteTestRows(): void
    {
        $identityIds = [
            self::USER_IDENTITY_ID,
            self::SECOND_USER_IDENTITY_ID,
            self::UNKNOWN_USER_IDENTITY_ID,
        ];

        $this->db->createCommand()
            ->delete('{{%telegram_identity_profiles}}', ['user_identity_id' => $identityIds])
            ->execute();
        $this->db->createCommand()
            ->delete('{{%user_identity}}', ['id' => $identityIds])
            ->execute();
        $this->db->createCommand()
            ->delete('{{%user}}', ['id' => self::USER_ID])
            ->execute();
    }
}
