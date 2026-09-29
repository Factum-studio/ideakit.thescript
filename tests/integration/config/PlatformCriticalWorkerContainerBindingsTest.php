<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use modules\platform\application\dto\CriticalWorkerSettings;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\handler\RunCriticalWorkerHandler;
use modules\platform\application\port\IBrokerReceiver;
use modules\platform\application\port\IWorkerExecutionGuard;
use modules\platform\application\port\IWorkerRuntime;
use modules\platform\application\route\BackgroundCommandRegistry;
use modules\platform\infrastructure\process\PcntlWorkerRuntime;
use modules\platform\presentation\console\CriticalWorkerController;
use Psr\Log\NullLogger;
use Yii;
use yii\db\Connection;
use yii\di\Container;

final class PlatformCriticalWorkerContainerBindingsTest extends Unit
{
    /** @dataProvider configurations */
    public function testBindingsAreLazyAndProvideFreshWorkerResources(string $application): void
    {
        $connection = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->close();
        $originalContainer = Yii::$container;
        $originalEnvironment = $_ENV;
        Yii::$container = new Container();
        $_ENV['RABBITMQ_HOST'] = '127.0.0.1';
        $_ENV['RABBITMQ_PORT'] = '1';
        $_ENV['RABBITMQ_USER'] = 'synthetic-user';
        $_ENV['RABBITMQ_PASSWORD'] = 'synthetic-test-only';
        $_ENV['RABBITMQ_VHOST'] = 'synthetic-vhost';
        try {
            $config = require dirname(__DIR__, 3) . '/config/' . $application . '.php';
            self::assertIsArray($config);
            if ($application === 'console') {
                self::assertSame(CriticalWorkerController::class, $config['controllerMap']['platform-worker']['class']);
            }
            self::assertFalse($connection->isActive);
            self::assertInstanceOf(BackgroundCommandRegistry::class, Yii::$container->get(BackgroundCommandRegistry::class));
            self::assertInstanceOf(RunCriticalWorkerHandler::class, Yii::$container->get(RunCriticalWorkerHandler::class));
            self::assertNotSame(Yii::$container->get(IBrokerReceiver::class), Yii::$container->get(IBrokerReceiver::class));
            self::assertNotSame(Yii::$container->get(IWorkerRuntime::class), Yii::$container->get(IWorkerRuntime::class));
            self::assertFalse($connection->isActive);
        } finally {
            Yii::$container = $originalContainer;
            $_ENV = $originalEnvironment;
        }
    }

    /** @dataProvider invalidOptions */
    public function testInvalidOptionsDoNotStartWorker(mixed $limit, mixed $maxRuntime): void
    {
        $runtime = $this->createMock(IWorkerRuntime::class);
        $runtime->expects(self::never())->method('start');
        $controller = $this->controller($runtime);
        $controller->limit = $limit;
        $controller->maxRuntime = $maxRuntime;
        $controller->expects(self::once())->method('stderr')->with("configuration_invalid\n");
        $controller->expects(self::never())->method('stdout');
        self::assertSame(2, $controller->actionCritical());
    }

    public function testMissingProductionHandlerFailsBeforeReceive(): void
    {
        $runtime = $this->createMock(IWorkerRuntime::class);
        $runtime->expects(self::never())->method('start');
        $controller = $this->controller($runtime);
        $controller->expects(self::once())->method('stderr')->with(CriticalWorkerError::HANDLER_MISSING->value . "\n");
        $controller->expects(self::never())->method('stdout');
        self::assertSame(1, $controller->actionCritical());
    }

    /** @return CriticalWorkerController&\PHPUnit\Framework\MockObject\MockObject */
    private function controller(IWorkerRuntime $runtime): CriticalWorkerController
    {
        $receiver = $this->createMock(IBrokerReceiver::class);
        $receiver->expects(self::never())->method('receive');
        $handler = new RunCriticalWorkerHandler(
            $receiver,
            new BackgroundCommandRegistry([]),
            $runtime,
            $this->createMock(IWorkerExecutionGuard::class),
            new CriticalWorkerSettings(4, 10, 1, 256, 192, 10),
            new NullLogger(),
        );

        return $this->getMockBuilder(CriticalWorkerController::class)
            ->setConstructorArgs(['platform-worker', Yii::$app, $handler, 1000, 3600])
            ->onlyMethods(['stdout', 'stderr'])
            ->getMock();
    }

    /** @return iterable<string, array{string}> */
    public static function configurations(): iterable
    {
        yield 'web' => ['web'];
        yield 'console' => ['console'];
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function invalidOptions(): iterable
    {
        foreach (['0', '-1', '1.5', '10001', '999999999999999999999', '', true, 2] as $value) {
            yield 'limit ' . (string) json_encode($value) => [$value, null];
        }
        foreach (['0', '-1', '1.5', '3601', '999999999999999999999', '', true, 2] as $value) {
            yield 'runtime ' . (string) json_encode($value) => [null, $value];
        }
    }
}
