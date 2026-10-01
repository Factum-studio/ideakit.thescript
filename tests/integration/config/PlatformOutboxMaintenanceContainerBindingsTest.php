<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use modules\platform\application\command\ClearDeliveredOutboxPayloadCommand;
use modules\platform\application\command\RecoverExpiredOutboxCommand;
use modules\platform\application\dto\OutboxPayloadCleanupReceipt;
use modules\platform\application\dto\OutboxRecoveryReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\dto\OutboxStatusView;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\handler\RecoverExpiredOutboxHandler;
use modules\platform\application\handler\ClearDeliveredOutboxPayloadHandler;
use modules\platform\application\handler\GetOutboxStatusHandler;
use modules\platform\application\port\IOutboxPayloadCleanupStore;
use modules\platform\application\port\IOutboxRecoveryStore;
use modules\platform\application\port\IOutboxStatusReader;
use modules\platform\application\query\GetOutboxStatusQuery;
use modules\platform\infrastructure\db\DbOutboxPayloadCleanupStore;
use modules\platform\infrastructure\db\DbOutboxRecoveryStore;
use modules\platform\infrastructure\db\DbOutboxStatusReader;
use modules\platform\infrastructure\logging\YiiOutboxMaintenanceLogger;
use modules\platform\presentation\console\OutboxMaintenanceController;
use Psr\Log\LoggerInterface;
use Yii;
use yii\db\Connection;
use yii\di\Container;

final class PlatformOutboxMaintenanceContainerBindingsTest extends Unit
{
    public function testConsoleBindingIsLazyAndRecoveryNeedsNoBrokerConnection(): void
    {
        $connection = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame('ideakit_test', $connection->createCommand('SELECT current_database()')->queryScalar());
        self::assertSame(0, (int) $connection->createCommand('SELECT count(*) FROM {{%outbox_messages}}')->queryScalar());
        $connection->close();
        $originalContainer = Yii::$container;
        $originalEnvironment = $_ENV;
        Yii::$container = new Container();
        $_ENV['RABBITMQ_HOST'] = '127.0.0.1';
        $_ENV['RABBITMQ_PORT'] = '1';

        try {
            $config = require dirname(__DIR__, 3) . '/config/console.php';
            self::assertSame(OutboxMaintenanceController::class, $config['controllerMap']['platform-outbox-maintenance']['class']);
            self::assertSame(['info'], $config['components']['log']['targets'][1]['levels']);
            self::assertSame(['platform.outbox_maintenance'], $config['components']['log']['targets'][1]['categories']);
            self::assertInstanceOf(DbOutboxRecoveryStore::class, Yii::$container->get(IOutboxRecoveryStore::class));
            $handler = Yii::$container->get(RecoverExpiredOutboxHandler::class);
            self::assertInstanceOf(RecoverExpiredOutboxHandler::class, $handler);
            self::assertInstanceOf(DbOutboxPayloadCleanupStore::class, Yii::$container->get(IOutboxPayloadCleanupStore::class));
            self::assertInstanceOf(DbOutboxStatusReader::class, Yii::$container->get(IOutboxStatusReader::class));
            $controller = Yii::$container->get(OutboxMaintenanceController::class, ['platform-outbox-maintenance', Yii::$app]);
            self::assertInstanceOf(OutboxMaintenanceController::class, $controller);
            self::assertFalse($connection->isActive);
            $receipt = $handler->handle(new RecoverExpiredOutboxCommand(10));
            self::assertSame(0, $receipt->retryScheduled);
            self::assertSame(0, $receipt->failed);
            self::assertSame(0, Yii::$container->get(ClearDeliveredOutboxPayloadHandler::class)
                ->handle(new ClearDeliveredOutboxPayloadCommand(1))->cleared);
            $view = Yii::$container->get(GetOutboxStatusHandler::class)->handle(new GetOutboxStatusQuery());
            self::assertSame([0, 0, 0], [$view->failed, $view->expiredLeases, $view->due]);
        } finally {
            Yii::$container = $originalContainer;
            $_ENV = $originalEnvironment;
        }
    }

    /** @dataProvider invalidLimits */
    public function testInvalidConsoleLimitFailsBeforeRecovery(mixed $limit): void
    {
        $store = $this->createMock(IOutboxRecoveryStore::class);
        $store->expects(self::never())->method('recoverExpired');
        $controller = $this->controller($store);
        $controller->limit = $limit;
        $controller->expects(self::never())->method('stdout');
        $controller->expects(self::once())->method('stderr')->with("Invalid recovery limit.\n");

        self::assertSame(2, $controller->actionRecover());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLimits(): iterable
    {
        foreach (['abc', '1.5', '0', '-1', '101', '', true, [], 10] as $index => $value) {
            yield 'invalid ' . $index => [$value];
        }
    }

    /** @dataProvider validLimits */
    public function testConsoleReportsOnlyCounters(?string $limit, int $expected): void
    {
        $store = $this->createMock(IOutboxRecoveryStore::class);
        $store->expects(self::once())->method('recoverExpired')->with($expected)->willReturn(new OutboxRecoveryReceipt(2, 1));
        $controller = $this->controller($store);
        $controller->limit = $limit;
        $controller->expects(self::once())->method('stdout')->with("retry_scheduled=2 failed=1\n");
        $controller->expects(self::never())->method('stderr');

        self::assertSame(0, $controller->actionRecover());
    }

    /** @return iterable<string, array{string|null, int}> */
    public static function validLimits(): iterable
    {
        yield 'default' => [null, 7];
        yield 'minimum' => ['1', 1];
        yield 'maximum' => ['100', 100];
    }

    public function testConsoleOperationFailureIsSafe(): void
    {
        $store = $this->createMock(IOutboxRecoveryStore::class);
        $store->method('recoverExpired')->willThrowException(new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE));
        $controller = $this->controller($store);
        $controller->expects(self::never())->method('stdout');
        $controller->expects(self::once())->method('stderr')->with("Outbox recovery failed: persistence_failure.\n");

        self::assertSame(1, $controller->actionRecover());
    }

    /** @dataProvider invalidLimits */
    public function testInvalidCleanupLimitFailsBeforeMutation(mixed $limit): void
    {
        $recovery = $this->createMock(IOutboxRecoveryStore::class);
        $cleanup = $this->createMock(IOutboxPayloadCleanupStore::class);
        $cleanup->expects(self::never())->method('clearDue');
        $controller = $this->controller($recovery, $cleanup);
        $controller->limit = $limit;
        $controller->expects(self::never())->method('stdout');
        $controller->expects(self::once())->method('stderr')->with("Invalid cleanup limit.\n");

        self::assertSame(2, $controller->actionClearPayload());
    }

    /** @dataProvider cleanupLimits */
    public function testCleanupReportsOnlyCount(?string $limit, int $expected): void
    {
        $recovery = $this->createMock(IOutboxRecoveryStore::class);
        $cleanup = $this->createMock(IOutboxPayloadCleanupStore::class);
        $cleanup->expects(self::once())->method('clearDue')->with($expected)
            ->willReturn(new OutboxPayloadCleanupReceipt(3));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('platform.outbox_maintenance.payload_cleared', ['cleared' => 3]);
        $controller = $this->controller($recovery, $cleanup, null, $logger);
        $controller->limit = $limit;
        $controller->expects(self::once())->method('stdout')->with("cleared=3\n");
        $controller->expects(self::never())->method('stderr');

        self::assertSame(0, $controller->actionClearPayload());
    }

    /** @return iterable<string, array{string|null, int}> */
    public static function cleanupLimits(): iterable
    {
        yield 'default' => [null, 100];
        yield 'minimum' => ['1', 1];
        yield 'maximum' => ['100', 100];
    }

    public function testCleanupOperationFailureIsSafe(): void
    {
        $recovery = $this->createMock(IOutboxRecoveryStore::class);
        $cleanup = $this->createMock(IOutboxPayloadCleanupStore::class);
        $cleanup->method('clearDue')->willThrowException(new OutboxMaintenanceException(
            OutboxMaintenanceError::PERSISTENCE_FAILURE,
            new \RuntimeException('synthetic-private-detail', 0, new \RuntimeException('synthetic-inner-detail')),
        ));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'platform.outbox_maintenance.failed',
            ['operation' => 'clear_payload', 'reason' => 'persistence_failure'],
        );
        $controller = $this->controller($recovery, $cleanup, null, $logger);
        $controller->expects(self::never())->method('stdout');
        $controller->expects(self::once())->method('stderr')->with("Outbox cleanup failed: persistence_failure.\n");

        self::assertSame(1, $controller->actionClearPayload());
    }

    public function testStatusIsReadOnlyAndReportsOnlyCounts(): void
    {
        $recovery = $this->createMock(IOutboxRecoveryStore::class);
        $reader = $this->createMock(IOutboxStatusReader::class);
        $reader->expects(self::once())->method('getStatus')->willReturn(new OutboxStatusView(1, 2, 3));
        $controller = $this->controller($recovery, null, $reader);
        $controller->expects(self::once())->method('stdout')->with("failed=1 expired_leases=2 due=3\n");
        $controller->expects(self::never())->method('stderr');
        self::assertNotContains('limit', $controller->options('status'));

        self::assertSame(0, $controller->actionStatus());
    }

    public function testStatusFailureIsNotAnEmptySuccess(): void
    {
        $recovery = $this->createMock(IOutboxRecoveryStore::class);
        $reader = $this->createMock(IOutboxStatusReader::class);
        $reader->method('getStatus')->willThrowException(new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE));
        $controller = $this->controller($recovery, null, $reader);
        $controller->expects(self::never())->method('stdout');
        $controller->expects(self::once())->method('stderr')->with("Outbox status failed: persistence_failure.\n");

        self::assertSame(1, $controller->actionStatus());
    }

    /** @return OutboxMaintenanceController&\PHPUnit\Framework\MockObject\MockObject */
    private function controller(
        IOutboxRecoveryStore $store,
        ?IOutboxPayloadCleanupStore $cleanup = null,
        ?IOutboxStatusReader $reader = null,
        ?LoggerInterface $logger = null,
    ): OutboxMaintenanceController {
        return $this->getMockBuilder(OutboxMaintenanceController::class)
            ->setConstructorArgs([
                'platform-outbox-maintenance', Yii::$app,
                new RecoverExpiredOutboxHandler($store, new OutboxRelaySettings(5, 600, 15, 900)),
                7,
                new ClearDeliveredOutboxPayloadHandler($cleanup ?? $this->createMock(IOutboxPayloadCleanupStore::class)),
                new GetOutboxStatusHandler($reader ?? $this->createMock(IOutboxStatusReader::class)),
                $logger ?? $this->createMock(LoggerInterface::class),
            ])->onlyMethods(['stdout', 'stderr'])->getMock();
    }
}
