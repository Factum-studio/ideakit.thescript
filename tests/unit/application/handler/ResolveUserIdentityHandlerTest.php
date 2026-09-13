<?php

declare(strict_types=1);

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\command\ResolveUserIdentityCommand;
use core\application\exception\UserIdentityConcurrencyException;
use core\application\exception\UserIdentityOwnerNotFoundException;
use core\application\exception\UserIdentityResolutionPersistenceException;
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
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ResolveUserIdentityHandlerTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ae0';
    private const IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ae1';

    public function testReturnsExistingIdentityOwnerWithoutWrites(): void
    {
        $resolvedAt = $this->utc('2026-09-05 11:00:00');
        $identity = $this->identity($resolvedAt);
        $user = $this->user(UserStatus::STATUS_INACTIVE, $resolvedAt);
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $transactionManager = new RecordingTransactionManager();

        $identityRepository->expects(self::once())
            ->method('findByProviderAndClientId')
            ->with('telegram', '1000000000000000001')
            ->willReturn($identity);
        $identityRepository->expects(self::never())->method('save');
        $userRepository->expects(self::once())
            ->method('findById')
            ->with(self::callback(
                static fn (UserId $id): bool => $id->value() === self::USER_ID,
            ))
            ->willReturn($user);
        $userRepository->expects(self::never())->method('save');
        $security->expects(self::never())->method('generateRandomString');

        $result = $this->handler(
            $identityRepository,
            $userRepository,
            $security,
            $transactionManager,
        )->handle(new ResolveUserIdentityCommand(
            'telegram',
            '1000000000000000001',
            $resolvedAt,
        ));

        self::assertSame(self::USER_ID, $result->userId);
        self::assertSame(self::IDENTITY_ID, $result->userIdentityId);
        self::assertSame(UserStatus::STATUS_INACTIVE, $result->userStatus);
        self::assertFalse($result->created);
        self::assertSame(1, $transactionManager->attempts);
        self::assertFalse($transactionManager->rolledBack);
    }

    public function testCreatesOrdinaryUserAndIdentityAtomically(): void
    {
        $resolvedAt = $this->utc('2026-09-05 11:01:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $transactionManager = new RecordingTransactionManager();
        $savedUser = null;
        $savedIdentity = null;

        $identityRepository->expects(self::once())
            ->method('findByProviderAndClientId')
            ->with('telegram', '1000000000000000002')
            ->willReturn(null);
        $userRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (User $user) use (&$savedUser): void {
                $savedUser = $user;
            });
        $identityRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (UserIdentity $identity) use (&$savedIdentity): void {
                $savedIdentity = $identity;
            });
        $security->expects(self::once())
            ->method('generateRandomString')
            ->with(32)
            ->willReturn('0123456789abcdef0123456789abcdef');

        $result = $this->handler(
            $identityRepository,
            $userRepository,
            $security,
            $transactionManager,
        )->handle(new ResolveUserIdentityCommand(
            'telegram',
            '1000000000000000002',
            $resolvedAt,
        ));

        self::assertInstanceOf(User::class, $savedUser);
        self::assertNull($savedUser->getSurname());
        self::assertNull($savedUser->getName());
        self::assertSame(Role::ROLE_USER, $savedUser->getRole()->value());
        self::assertSame(UserStatus::STATUS_ACTIVE, $savedUser->getStatus()->value());
        self::assertSame('0123456789abcdef0123456789abcdef', $savedUser->getAuthKey());
        self::assertEquals($resolvedAt, $savedUser->getCreatedAt());
        self::assertEquals($resolvedAt, $savedUser->getUpdatedAt());

        self::assertInstanceOf(UserIdentity::class, $savedIdentity);
        self::assertSame($savedUser->getId()->value(), $savedIdentity->getUserId()->value());
        self::assertSame('telegram', $savedIdentity->getProvider());
        self::assertSame('1000000000000000002', $savedIdentity->getProviderClientId());
        self::assertEquals($resolvedAt, $savedIdentity->getCreatedAt());
        self::assertSame($savedUser->getId()->value(), $result->userId);
        self::assertSame($savedIdentity->getId()->value(), $result->userIdentityId);
        self::assertSame(UserStatus::STATUS_ACTIVE, $result->userStatus);
        self::assertTrue($result->created);
        self::assertFalse($transactionManager->rolledBack);
    }

    public function testRejectsIdentityWhoseOwnerIsMissing(): void
    {
        $resolvedAt = $this->utc('2026-09-05 11:02:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $transactionManager = new RecordingTransactionManager();

        $identityRepository->method('findByProviderAndClientId')
            ->willReturn($this->identity($resolvedAt));
        $userRepository->method('findById')->willReturn(null);

        try {
            $this->handler(
                $identityRepository,
                $userRepository,
                $security,
                $transactionManager,
            )->handle(new ResolveUserIdentityCommand(
                'telegram',
                '1000000000000000001',
                $resolvedAt,
            ));
            self::fail('Expected missing identity owner to be rejected.');
        } catch (UserIdentityOwnerNotFoundException $exception) {
            self::assertSame('user_identity_owner_not_found', $exception->getMessage());
        }

        self::assertTrue($transactionManager->rolledBack);
    }

    public function testIdentityPersistenceFailureRollsBackUserCreation(): void
    {
        $resolvedAt = $this->utc('2026-09-05 11:03:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $transactionManager = new RecordingTransactionManager();

        $identityRepository->method('findByProviderAndClientId')->willReturn(null);
        $userRepository->expects(self::once())->method('save');
        $identityRepository->expects(self::once())
            ->method('save')
            ->willThrowException(new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
            ));
        $security->method('generateRandomString')
            ->willReturn('0123456789abcdef0123456789abcdef');

        try {
            $this->handler(
                $identityRepository,
                $userRepository,
                $security,
                $transactionManager,
            )->handle(new ResolveUserIdentityCommand(
                'telegram',
                '1000000000000000003',
                $resolvedAt,
            ));
            self::fail('Expected identity persistence failure.');
        } catch (UserIdentityResolutionPersistenceException $exception) {
            self::assertSame('user_identity_resolution_persistence_failure', $exception->getMessage());
        }

        self::assertTrue($transactionManager->rolledBack);
    }

    public function testPreservesExactIdentityConcurrencyConflict(): void
    {
        $resolvedAt = $this->utc('2026-09-05 11:04:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $transactionManager = new RecordingTransactionManager();
        $concurrencyException = new UserIdentityConcurrencyException(
            'user_identity_concurrency_conflict',
        );

        $identityRepository->method('findByProviderAndClientId')->willReturn(null);
        $identityRepository->method('save')->willThrowException($concurrencyException);
        $security->method('generateRandomString')
            ->willReturn('0123456789abcdef0123456789abcdef');

        try {
            $this->handler(
                $identityRepository,
                $userRepository,
                $security,
                $transactionManager,
            )->handle(new ResolveUserIdentityCommand(
                'telegram',
                '1000000000000000004',
                $resolvedAt,
            ));
            self::fail('Expected identity concurrency conflict.');
        } catch (UserIdentityConcurrencyException $exception) {
            self::assertSame($concurrencyException, $exception);
        }

        self::assertTrue($transactionManager->rolledBack);
    }

    public function testWrapsUnknownFailureWithoutClassifyingItAsConcurrency(): void
    {
        $resolvedAt = $this->utc('2026-09-05 11:05:00');
        $identityRepository = $this->createMock(IUserIdentityRepository::class);
        $userRepository = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);
        $transactionManager = new RecordingTransactionManager();
        $unknownFailure = new RuntimeException('synthetic database failure');

        $identityRepository->method('findByProviderAndClientId')->willReturn(null);
        $identityRepository->method('save')->willThrowException($unknownFailure);
        $security->method('generateRandomString')
            ->willReturn('0123456789abcdef0123456789abcdef');

        try {
            $this->handler(
                $identityRepository,
                $userRepository,
                $security,
                $transactionManager,
            )->handle(new ResolveUserIdentityCommand(
                'telegram',
                '1000000000000000005',
                $resolvedAt,
            ));
            self::fail('Expected unknown failure to be wrapped.');
        } catch (UserIdentityResolutionPersistenceException $exception) {
            self::assertSame('user_identity_resolution_persistence_failure', $exception->getMessage());
            self::assertSame($unknownFailure, $exception->getPrevious());
            self::assertNotInstanceOf(UserIdentityConcurrencyException::class, $exception);
        }

        self::assertTrue($transactionManager->rolledBack);
        self::assertSame(1, $transactionManager->attempts);
    }

    /**
     * @dataProvider invalidCommands
     */
    public function testRejectsInvalidCommandInput(
        string $provider,
        string $providerClientId,
        DateTimeImmutable $resolvedAt,
        string $reason,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        new ResolveUserIdentityCommand($provider, $providerClientId, $resolvedAt);
    }

    /**
     * @return iterable<string, array{string, string, DateTimeImmutable, string}>
     */
    public static function invalidCommands(): iterable
    {
        $utc = new DateTimeZone('UTC');

        yield 'empty provider' => ['', 'subject', new DateTimeImmutable('2026-09-05', $utc), 'invalid_identity_provider'];
        yield 'provider too long' => [str_repeat('a', 51), 'subject', new DateTimeImmutable('2026-09-05', $utc), 'invalid_identity_provider'];
        yield 'empty subject' => ['telegram', '', new DateTimeImmutable('2026-09-05', $utc), 'invalid_identity_subject'];
        yield 'subject too long' => ['telegram', str_repeat('1', 256), new DateTimeImmutable('2026-09-05', $utc), 'invalid_identity_subject'];
        yield 'non UTC time' => ['telegram', 'subject', new DateTimeImmutable('2026-09-05T12:00:00+05:00'), 'resolved_at_must_be_utc'];
    }

    private function handler(
        IUserIdentityRepository $identityRepository,
        IUserRepository $userRepository,
        ISecurityService $security,
        ITransactionManager $transactionManager,
    ): ResolveUserIdentityHandler {
        return new ResolveUserIdentityHandler(
            $identityRepository,
            $userRepository,
            $security,
            $transactionManager,
        );
    }

    private function identity(DateTimeImmutable $createdAt): UserIdentity
    {
        return new UserIdentity(
            new UserIdentityId(self::IDENTITY_ID),
            new UserId(self::USER_ID),
            'telegram',
            '1000000000000000001',
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

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

final class RecordingTransactionManager implements ITransactionManager
{
    public int $attempts = 0;
    public bool $rolledBack = false;

    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollback(): void
    {
        $this->rolledBack = true;
    }

    public function transactional(callable $callback): mixed
    {
        ++$this->attempts;

        try {
            return $callback($this);
        } catch (Throwable $exception) {
            $this->rollback();

            throw $exception;
        }
    }
}
