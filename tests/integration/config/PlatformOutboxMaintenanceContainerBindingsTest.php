<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use modules\platform\application\command\RecoverExpiredOutboxCommand;
use modules\platform\application\dto\OutboxRecoveryReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\handler\RecoverExpiredOutboxHandler;
use modules\platform\application\port\IOutboxRecoveryStore;
use modules\platform\infrastructure\db\DbOutboxRecoveryStore;
use modules\platform\presentation\console\OutboxMaintenanceController;
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
            self::assertInstanceOf(DbOutboxRecoveryStore::class, Yii::$container->get(IOutboxRecoveryStore::class));
            $handler = Yii::$container->get(RecoverExpiredOutboxHandler::class);
            self::assertInstanceOf(RecoverExpiredOutboxHandler::class, $handler);
            self::assertFalse($connection->isActive);
            $receipt = $handler->handle(new RecoverExpiredOutboxCommand(10));
            self::assertSame(0, $receipt->retryScheduled);
            self::assertSame(0, $receipt->failed);
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

    /** @return OutboxMaintenanceController&\PHPUnit\Framework\MockObject\MockObject */
    private function controller(IOutboxRecoveryStore $store): OutboxMaintenanceController
    {
        return $this->getMockBuilder(OutboxMaintenanceController::class)
            ->setConstructorArgs([
                'platform-outbox-maintenance', Yii::$app,
                new RecoverExpiredOutboxHandler($store, new OutboxRelaySettings(5, 600, 15, 900)),
                7,
            ])->onlyMethods(['stdout', 'stderr'])->getMock();
    }
}
