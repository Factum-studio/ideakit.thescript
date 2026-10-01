<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Codeception\Test\Unit;
use modules\platform\application\command\RecoverExpiredOutboxCommand;
use modules\platform\application\dto\OutboxRecoveryReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\handler\RecoverExpiredOutboxHandler;
use modules\platform\application\port\IOutboxRecoveryStore;
use RuntimeException;

final class RecoverExpiredOutboxHandlerTest extends Unit
{
    public function testDelegatesBoundedBatchAndReturnsCounters(): void
    {
        $settings = new OutboxRelaySettings(5, 600, 15, 900);
        $receipt = new OutboxRecoveryReceipt(2, 1);
        $store = $this->createMock(IOutboxRecoveryStore::class);
        $store->expects(self::once())->method('recoverExpired')->with(3, $settings)->willReturn($receipt);

        self::assertSame($receipt, (new RecoverExpiredOutboxHandler($store, $settings))->handle(new RecoverExpiredOutboxCommand(3)));
    }

    /** @dataProvider invalidLimits */
    public function testRejectsInvalidLimit(int $limit): void
    {
        $this->expectException(OutboxMaintenanceException::class);
        $this->expectExceptionMessage('invalid_limit');

        new RecoverExpiredOutboxCommand($limit);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above maximum' => [101];
    }

    public function testUnexpectedStoreErrorExposesOnlySafeCode(): void
    {
        $cause = new RuntimeException('Synthetic database diagnostic.');
        $store = $this->createMock(IOutboxRecoveryStore::class);
        $store->method('recoverExpired')->willThrowException($cause);

        try {
            (new RecoverExpiredOutboxHandler($store, new OutboxRelaySettings(5, 600, 15, 900)))
                ->handle(new RecoverExpiredOutboxCommand(1));
            self::fail('Expected a safe maintenance error.');
        } catch (OutboxMaintenanceException $exception) {
            self::assertSame(OutboxMaintenanceError::PERSISTENCE_FAILURE, $exception->error);
            self::assertSame('persistence_failure', $exception->getMessage());
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    public function testKnownStoreErrorIsNotWrapped(): void
    {
        $cause = new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
        $store = $this->createMock(IOutboxRecoveryStore::class);
        $store->method('recoverExpired')->willThrowException($cause);

        try {
            (new RecoverExpiredOutboxHandler($store, new OutboxRelaySettings(5, 600, 15, 900)))
                ->handle(new RecoverExpiredOutboxCommand(1));
            self::fail('Expected the original maintenance error.');
        } catch (OutboxMaintenanceException $exception) {
            self::assertSame($cause, $exception);
        }
    }
}
