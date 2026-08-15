<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\entity;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use modules\users\domain\entity\UserIdentity;
use modules\users\domain\valueObject\IdentityProvider;
use modules\users\domain\valueObject\ProviderClientId;
use modules\users\domain\valueObject\UserId;
use modules\users\domain\valueObject\UserIdentityId;

final class UserIdentityTest extends Unit
{
    private const ID = '01890f4d-3c2a-7f48-8c0b-123456789abc';
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789abd';
    private const OTHER_USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789abe';

    public function testCreatesLinkedIdentityAndComparesTypedValues(): void
    {
        $id = UserIdentityId::fromString(self::ID);
        $userId = UserId::fromString(self::USER_ID);
        $providerClientId = ProviderClientId::fromString('123456789');
        $createdAt = self::utcTime();

        $identity = UserIdentity::create(
            $id,
            $userId,
            IdentityProvider::TELEGRAM,
            $providerClientId,
            $createdAt,
        );

        self::assertSame($id, $identity->id());
        self::assertSame($userId, $identity->userId());
        self::assertSame(IdentityProvider::TELEGRAM, $identity->provider());
        self::assertSame($providerClientId, $identity->providerClientId());
        self::assertSame($createdAt, $identity->createdAt());
        self::assertFalse($identity->isAnonymized());
        self::assertTrue($identity->belongsTo(UserId::fromString(self::USER_ID)));
        self::assertFalse($identity->belongsTo(UserId::fromString(self::OTHER_USER_ID)));
        self::assertTrue($identity->hasProvider(IdentityProvider::TELEGRAM));
        self::assertFalse($identity->hasProvider(IdentityProvider::INTERNAL));
    }

    public function testRestoresAnonymizedIdentity(): void
    {
        $identity = UserIdentity::restore(
            UserIdentityId::fromString(self::ID),
            UserId::fromString(self::USER_ID),
            IdentityProvider::TELEGRAM,
            null,
            self::utcTime(),
        );

        self::assertTrue($identity->isAnonymized());
        self::assertNull($identity->providerClientId());
    }

    public function testAnonymizesIdentityIdempotentlyWithoutChangingImmutableState(): void
    {
        $id = UserIdentityId::fromString(self::ID);
        $userId = UserId::fromString(self::USER_ID);
        $createdAt = self::utcTime();
        $identity = UserIdentity::create(
            $id,
            $userId,
            IdentityProvider::TELEGRAM,
            ProviderClientId::fromString('123456789'),
            $createdAt,
        );

        $identity->anonymize();
        $identity->anonymize();

        self::assertTrue($identity->isAnonymized());
        self::assertNull($identity->providerClientId());
        self::assertSame($id, $identity->id());
        self::assertSame($userId, $identity->userId());
        self::assertSame(IdentityProvider::TELEGRAM, $identity->provider());
        self::assertSame($createdAt, $identity->createdAt());
    }

    /**
     * @dataProvider constructionMethods
     */
    public function testRejectsNonUtcCreatedAt(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('created_at_must_be_utc');

        $arguments = [
            UserIdentityId::fromString(self::ID),
            UserId::fromString(self::USER_ID),
            IdentityProvider::TELEGRAM,
            ProviderClientId::fromString('123456789'),
            new DateTimeImmutable('2026-08-15 10:00:00', new DateTimeZone('Asia/Yekaterinburg')),
        ];

        if ($method === 'restore') {
            UserIdentity::restore(...$arguments);

            return;
        }

        UserIdentity::create(...$arguments);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function constructionMethods(): iterable
    {
        yield 'create' => ['create'];
        yield 'restore' => ['restore'];
    }

    private static function utcTime(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-15 07:00:00', new DateTimeZone('UTC'));
    }
}
