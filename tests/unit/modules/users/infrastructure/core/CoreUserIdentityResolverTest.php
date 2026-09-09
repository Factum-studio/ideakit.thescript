<?php

declare(strict_types=1);

namespace tests\unit\modules\users\infrastructure\core;

use Codeception\Test\Unit;
use core\application\exception\UserIdentityConcurrencyException as CoreConcurrencyException;
use core\application\exception\UserIdentityResolutionPersistenceException as CorePersistenceException;
use core\application\handler\ResolveUserIdentityHandler;
use core\application\port\ISecurityService;
use core\application\port\ITransactionManager;
use core\application\port\IUserIdentityRepository;
use core\application\port\IUserRepository;
use core\domain\entity\User;
use core\domain\entity\UserIdentity;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;
use DateTimeZone;
use modules\users\application\enum\UserAccountStatus;
use modules\users\application\exception\UserIdentityResolutionConcurrencyException;
use modules\users\application\exception\UserIdentityResolutionIntegrityException;
use modules\users\application\exception\UserIdentityResolutionPersistenceException;
use modules\users\domain\valueObject\TelegramUserId;
use modules\users\infrastructure\core\CoreUserIdentityResolver;
use ReflectionProperty;

final class CoreUserIdentityResolverTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ae0';
    private const IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ae1';
    private const TELEGRAM_USER_ID = '1000000000000000201';

    public function testMapsTelegramInputAndActiveCoreResult(): void
    {
        $resolvedAt = self::utc('2026-09-05 14:00:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $savedUser = null;
        $savedIdentity = null;

        $identityRepository->expects(self::once())
            ->method('findByProviderAndClientId')
            ->with('telegram', self::TELEGRAM_USER_ID)
            ->willReturn(null);
        $identityRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (UserIdentity $identity) use (&$savedIdentity): void {
                $savedIdentity = $identity;
            });
        $userRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (User $user) use (&$savedUser): void {
                $savedUser = $user;
            });
        $security->expects(self::once())
            ->method('generateRandomString')
            ->with(32)
            ->willReturn('0123456789abcdef0123456789abcdef');

        $result = $this->resolver(
            $identityRepository,
            $userRepository,
            $security,
        )->resolve(TelegramUserId::fromString(self::TELEGRAM_USER_ID), $resolvedAt);

        self::assertInstanceOf(User::class, $savedUser);
        self::assertInstanceOf(UserIdentity::class, $savedIdentity);
        self::assertSame('telegram', $savedIdentity->getProvider());
        self::assertSame(self::TELEGRAM_USER_ID, $savedIdentity->getProviderClientId());
        self::assertSame($resolvedAt, $savedIdentity->getCreatedAt());
        self::assertSame('UTC', $savedIdentity->getCreatedAt()->getTimezone()->getName());
        self::assertSame($savedUser->getId()->value(), $result->userId);
        self::assertSame($savedIdentity->getId()->value(), $result->userIdentityId);
        self::assertSame(UserAccountStatus::ACTIVE, $result->userStatus);
        self::assertTrue($result->created);
    }

    public function testMapsInactiveCoreStatus(): void
    {
        $resolvedAt = self::utc('2026-09-05 14:01:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);

        $identityRepository->method('findByProviderAndClientId')
            ->willReturn($this->identity($resolvedAt));
        $userRepository->method('findById')
            ->willReturn($this->user(UserStatus::STATUS_INACTIVE, $resolvedAt));

        $result = $this->resolver(
            $identityRepository,
            $userRepository,
            $security,
        )->resolve(TelegramUserId::fromString(self::TELEGRAM_USER_ID), $resolvedAt);

        self::assertSame(self::USER_ID, $result->userId);
        self::assertSame(self::IDENTITY_ID, $result->userIdentityId);
        self::assertSame(UserAccountStatus::INACTIVE, $result->userStatus);
        self::assertFalse($result->created);
    }

    public function testMapsCoreConcurrencyToRetryablePortFailure(): void
    {
        $resolvedAt = self::utc('2026-09-05 14:02:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $failure = new CoreConcurrencyException('user_identity_concurrency_conflict');

        $identityRepository->method('findByProviderAndClientId')->willReturn(null);
        $identityRepository->method('save')->willThrowException($failure);
        $security->method('generateRandomString')
            ->willReturn('0123456789abcdef0123456789abcdef');

        try {
            $this->resolver(
                $identityRepository,
                $userRepository,
                $security,
            )->resolve(TelegramUserId::fromString(self::TELEGRAM_USER_ID), $resolvedAt);
            self::fail('Expected a translated concurrency conflict.');
        } catch (UserIdentityResolutionConcurrencyException $exception) {
            self::assertSame('user_identity_resolution_concurrency_conflict', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testMapsMissingCoreOwnerToIntegrityFailure(): void
    {
        $resolvedAt = self::utc('2026-09-05 14:03:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);

        $identityRepository->method('findByProviderAndClientId')
            ->willReturn($this->identity($resolvedAt));
        $userRepository->method('findById')->willReturn(null);

        try {
            $this->resolver(
                $identityRepository,
                $userRepository,
                $security,
            )->resolve(TelegramUserId::fromString(self::TELEGRAM_USER_ID), $resolvedAt);
            self::fail('Expected an identity-owner integrity failure.');
        } catch (UserIdentityResolutionIntegrityException $exception) {
            self::assertSame('user_identity_resolution_integrity_failure', $exception->getMessage());
            self::assertSame('user_identity_owner_not_found', $exception->getPrevious()?->getMessage());
        }
    }

    public function testMapsCorePersistenceFailureWithoutLeakingDetails(): void
    {
        $resolvedAt = self::utc('2026-09-05 14:04:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $failure = new CorePersistenceException('core_internal_failure');

        $identityRepository->method('findByProviderAndClientId')->willThrowException($failure);

        try {
            $this->resolver(
                $identityRepository,
                $userRepository,
                $security,
            )->resolve(TelegramUserId::fromString(self::TELEGRAM_USER_ID), $resolvedAt);
            self::fail('Expected a translated persistence failure.');
        } catch (UserIdentityResolutionPersistenceException $exception) {
            self::assertSame('user_identity_resolution_persistence_failure', $exception->getMessage());
            self::assertStringNotContainsString('core_internal_failure', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testRejectsUnknownCoreStatusAsIntegrityFailure(): void
    {
        $resolvedAt = self::utc('2026-09-05 14:05:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $user = $this->user(UserStatus::STATUS_ACTIVE, $resolvedAt);
        $statusProperty = new ReflectionProperty(UserStatus::class, 'value');
        $statusProperty->setValue($user->getStatus(), 99);

        $identityRepository->method('findByProviderAndClientId')
            ->willReturn($this->identity($resolvedAt));
        $userRepository->method('findById')->willReturn($user);

        $this->expectException(UserIdentityResolutionIntegrityException::class);
        $this->expectExceptionMessage('user_identity_resolution_integrity_failure');

        $this->resolver(
            $identityRepository,
            $userRepository,
            $security,
        )->resolve(TelegramUserId::fromString(self::TELEGRAM_USER_ID), $resolvedAt);
    }

    private function resolver(
        IUserIdentityRepository $identityRepository,
        IUserRepository $userRepository,
        ISecurityService $security,
    ): CoreUserIdentityResolver {
        return new CoreUserIdentityResolver(new ResolveUserIdentityHandler(
            $identityRepository,
            $userRepository,
            $security,
            new CoreAdapterTransactionManager(),
        ));
    }

    private function identity(DateTimeImmutable $createdAt): UserIdentity
    {
        return new UserIdentity(
            new UserIdentityId(self::IDENTITY_ID),
            new UserId(self::USER_ID),
            'telegram',
            self::TELEGRAM_USER_ID,
            $createdAt,
        );
    }

    private function user(int $status, DateTimeImmutable $createdAt): User
    {
        return new User(
            new UserId(self::USER_ID),
            null,
            null,
            null,
            null,
            null,
            new Role(Role::ROLE_USER),
            null,
            new UserStatus($status),
            '0123456789abcdef0123456789abcdef',
            $createdAt,
            $createdAt,
        );
    }

    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

final class CoreAdapterTransactionManager implements ITransactionManager
{
    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollback(): void
    {
    }

    public function transactional(callable $callback): mixed
    {
        return $callback($this);
    }
}
