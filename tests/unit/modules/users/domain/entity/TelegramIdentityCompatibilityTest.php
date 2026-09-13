<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\entity;

use Codeception\Test\Unit;
use core\domain\entity\UserIdentity;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use DateTimeZone;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use modules\users\domain\valueObject\TelegramUserId;

final class TelegramIdentityCompatibilityTest extends Unit
{
    public function testProfileUsesCoreIdentityWithoutChangingItsProviderSubject(): void
    {
        $seenAt = new DateTimeImmutable('2026-09-03 10:00:00', new DateTimeZone('UTC'));
        $identity = new UserIdentity(
            new UserIdentityId('550e8400-e29b-41d4-a716-446655440000'),
            new UserId('01890f4d-3c2a-7f48-8c0b-123456789abe'),
            'telegram',
            TelegramUserId::fromString('123456789')->value(),
            $seenAt,
        );
        $profile = TelegramIdentityProfile::create(
            new TelegramIdentityProfileId('01890f4d-3c2a-7f48-8c0b-123456789abc'),
            $identity->getId(),
            TelegramProfileSnapshot::create('example_user', 'Example', null, 'ru'),
            $seenAt,
        );

        $profile->markBotBlocked($seenAt->modify('+1 minute'));
        $profile->anonymize();

        self::assertSame($identity->getId(), $profile->getUserIdentityId());
        self::assertTrue($profile->getProfileSnapshot()->isEmpty());
        self::assertFalse($profile->canReceiveInitiatedMessages());
        self::assertSame('telegram', $identity->getProvider());
        self::assertSame('123456789', $identity->getProviderClientId());
    }
}
