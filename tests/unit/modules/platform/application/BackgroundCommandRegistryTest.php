<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Codeception\Test\Unit;
use modules\platform\application\dto\BackgroundCommandRegistration;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\enum\BackgroundCommandOutcome;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\exception\BackgroundCommandRejectedException;
use modules\platform\application\exception\CriticalWorkerException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\port\IBackgroundCommandHandler;
use modules\platform\application\route\BackgroundCommandRegistry;
use tests\fixtures\platform\TestOutboxRoutes;

final class BackgroundCommandRegistryTest extends Unit
{
    /** @dataProvider completedOutcomes */
    public function testExactRegistrationPreservesMessageAndTypedOutcome(string $outcome): void
    {
        $message = new BrokerEnvelope(
            '01890f4d-3c2a-7f48-8c0b-123456789ac4',
            'telegram.update.received',
            '1.0',
            '01890f4d-3c2a-7f48-8c0b-123456789ac5',
            new TelegramUpdateReceivedPayload('01890f4d-3c2a-7f48-8c0b-123456789ac6'),
        );
        $expected = BackgroundCommandOutcome::from($outcome);
        $handler = $this->createMock(IBackgroundCommandHandler::class);
        $handler->expects(self::once())->method('handle')->with(self::identicalTo($message))->willReturn($expected);
        $registry = new BackgroundCommandRegistry([
            new BackgroundCommandRegistration($message->messageType, $message->schemaVersion, $handler),
        ], TestOutboxRoutes::registry());

        $registry->assertCriticalRouteRegistered();
        $resolved = $registry->requireHandler($message->messageType, $message->schemaVersion);
        self::assertSame($handler, $resolved);
        self::assertSame($expected, $resolved->handle($message));
    }

    /** @return iterable<string, array{string}> */
    public static function completedOutcomes(): iterable
    {
        yield 'saved effect' => ['completed'];
        yield 'confirmed duplicate' => ['already_completed'];
    }

    public function testDifferentVersionsAndDelimiterPairsRemainDistinct(): void
    {
        $first = $this->createMock(IBackgroundCommandHandler::class);
        $second = $this->createMock(IBackgroundCommandHandler::class);
        $registry = new BackgroundCommandRegistry([
            new BackgroundCommandRegistration('telegram.update.received', '1.0', $first),
            new BackgroundCommandRegistration('telegram.update.received', '2.0', $second),
            new BackgroundCommandRegistration('synthetic/command', '1.0', $first),
            new BackgroundCommandRegistration('synthetic', 'command/1.0', $second),
        ], TestOutboxRoutes::registry());

        self::assertSame($first, $registry->requireHandler('telegram.update.received', '1.0'));
        self::assertSame($second, $registry->requireHandler('telegram.update.received', '2.0'));
        self::assertSame($first, $registry->requireHandler('synthetic/command', '1.0'));
        self::assertSame($second, $registry->requireHandler('synthetic', 'command/1.0'));
    }

    public function testAcceptsUtf8CharacterLimitsWithoutChangingKeys(): void
    {
        $handler = $this->createMock(IBackgroundCommandHandler::class);
        $type = str_repeat('я', 64);
        $version = str_repeat('в', 48);
        $registry = new BackgroundCommandRegistry([new BackgroundCommandRegistration($type, $version, $handler)], TestOutboxRoutes::registry());

        self::assertSame($handler, $registry->requireHandler($type, $version));
    }

    /** @dataProvider invalidRegistrations */
    public function testRejectsInvalidRegistrationWithoutExposingInput(string $type, string $version): void
    {
        try {
            new BackgroundCommandRegistration($type, $version, $this->createMock(IBackgroundCommandHandler::class));
            self::fail('Invalid registration was accepted.');
        } catch (CriticalWorkerException $exception) {
            self::assertSame(CriticalWorkerError::CONFIGURATION_INVALID, $exception->error);
            self::assertSame('configuration_invalid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidRegistrations(): iterable
    {
        foreach (['type' => 64, 'version' => 48] as $field => $limit) {
            foreach ([
                'empty' => '',
                'blank' => '   ',
                'control' => "synthetic\n",
                'delete' => "synthetic\x7f",
                'invalid utf8' => "\xc3\x28",
                'ascii overflow' => str_repeat('x', $limit + 1),
                'utf8 overflow' => str_repeat('я', $limit + 1),
            ] as $case => $value) {
                yield $field . ' ' . $case => $field === 'type' ? [$value, '1.0'] : ['synthetic.command', $value];
            }
        }
    }

    public function testRejectsDuplicatePairBeforeDispatch(): void
    {
        $first = $this->createMock(IBackgroundCommandHandler::class);
        $second = $this->createMock(IBackgroundCommandHandler::class);
        $first->expects(self::never())->method('handle');
        $second->expects(self::never())->method('handle');
        $this->expectException(CriticalWorkerException::class);
        $this->expectExceptionMessage('configuration_invalid');

        new BackgroundCommandRegistry([
            new BackgroundCommandRegistration('telegram.update.received', '1.0', $first),
            new BackgroundCommandRegistration('telegram.update.received', '1.0', $second),
        ], TestOutboxRoutes::registry());
    }

    /** @dataProvider unsupportedContracts */
    public function testDoesNotDispatchUnknownContractOrSubstituteVersion(string $type, string $version): void
    {
        $handler = $this->createMock(IBackgroundCommandHandler::class);
        $handler->expects(self::never())->method('handle');
        $registry = new BackgroundCommandRegistry([
            new BackgroundCommandRegistration('telegram.update.received', '1.0', $handler),
        ], TestOutboxRoutes::registry());
        try {
            $registry->requireHandler($type, $version);
            self::fail('Unsupported contract was accepted.');
        } catch (CriticalWorkerException $exception) {
            self::assertSame(CriticalWorkerError::UNSUPPORTED_CONTRACT, $exception->error);
            self::assertSame('unsupported_contract', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupportedContracts(): iterable
    {
        yield 'unknown type' => ['synthetic.command', '1.0'];
        yield 'unknown version' => ['telegram.update.received', '1.1'];
        yield 'different case' => ['Telegram.update.received', '1.0'];
        yield 'untrimmed version' => ['telegram.update.received', '1.0 '];
    }

    /** @dataProvider missingCriticalRoutes */
    public function testRequiresExactCriticalRouteBeforeConsumer(?string $type, ?string $version): void
    {
        $registrations = [];
        if ($type !== null && $version !== null) {
            $registrations[] = new BackgroundCommandRegistration(
                $type,
                $version,
                $this->createMock(IBackgroundCommandHandler::class),
            );
        }
        $registry = new BackgroundCommandRegistry($registrations, TestOutboxRoutes::registry());
        try {
            $registry->assertCriticalRouteRegistered();
            self::fail('Missing critical route was accepted.');
        } catch (CriticalWorkerException $exception) {
            self::assertSame(CriticalWorkerError::HANDLER_MISSING, $exception->error);
            self::assertSame('handler_missing', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function missingCriticalRoutes(): iterable
    {
        yield 'empty registry' => [null, null];
        yield 'different type' => ['synthetic.command', '1.0'];
        yield 'different version' => ['telegram.update.received', '2.0'];
    }

    public function testTerminalRefusalHasOnlyFixedSafeMessage(): void
    {
        $exception = new BackgroundCommandRejectedException();

        self::assertSame('handler_rejected', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }
}
