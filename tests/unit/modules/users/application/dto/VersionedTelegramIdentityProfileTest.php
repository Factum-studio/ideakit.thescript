<?php

declare(strict_types=1);

namespace tests\unit\modules\users\application\dto;

use Codeception\Test\Unit;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use modules\users\application\dto\VersionedTelegramIdentityProfile;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;

final class VersionedTelegramIdentityProfileTest extends Unit
{
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789abc';
    private const USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789abd';

    public function testKeepsProfileAndNonNegativeLockVersion(): void
    {
        $profile = self::profile();
        $result = new VersionedTelegramIdentityProfile($profile, 3);

        self::assertSame($profile, $result->profile());
        self::assertSame(3, $result->lockVersion());
    }

    public function testRejectsNegativeLockVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('negative_telegram_profile_lock_version');

        new VersionedTelegramIdentityProfile(self::profile(), -1);
    }

    private static function profile(): TelegramIdentityProfile
    {
        return TelegramIdentityProfile::create(
            new TelegramIdentityProfileId(self::PROFILE_ID),
            new UserIdentityId(self::USER_IDENTITY_ID),
            TelegramProfileSnapshot::create('example_user', 'Example', 'User', 'en'),
            new DateTimeImmutable('2026-09-04 10:00:00', new DateTimeZone('UTC')),
        );
    }
}
