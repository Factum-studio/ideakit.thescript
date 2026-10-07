<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\handler\DeclareMessagingTopologyHandler;
use modules\platform\application\port\IBrokerPublisher;
use modules\platform\application\port\IBrokerReceiver;
use modules\platform\application\port\IBrokerTopology;
use modules\platform\infrastructure\rabbitmq\RabbitMqPublisher;
use modules\platform\infrastructure\rabbitmq\RabbitMqReceiver;
use modules\platform\infrastructure\rabbitmq\RabbitMqTopology;
use modules\platform\presentation\console\MessagingController;
use Yii;
use yii\console\ExitCode;
use yii\di\Container;

final class PlatformBrokerContainerBindingsTest extends Unit
{
    /** @dataProvider configurations */
    public function testConfigurationIsLazyAndResolvesTransportWithoutNetworkIo(string $application, bool $configured): void
    {
        $originalContainer = Yii::$container;
        $originalEnvironment = $_ENV;
        Yii::$container = new Container();
        foreach (array_keys($_ENV) as $name) {
            if (str_starts_with($name, 'RABBITMQ_')) {
                unset($_ENV[$name]);
            }
        }
        if ($configured) {
            $_ENV += [
                'RABBITMQ_HOST' => '127.0.0.1',
                'RABBITMQ_PORT' => '1',
                'RABBITMQ_USER' => 'synthetic-user',
                'RABBITMQ_PASSWORD' => 'synthetic-test-only',
                'RABBITMQ_VHOST' => 'synthetic-vhost',
            ];
        }
        try {
            $configuration = require dirname(__DIR__, 3) . '/config/' . $application . '.php';
            self::assertIsArray($configuration);
            if ($application === 'console') {
                self::assertSame(MessagingController::class, $configuration['controllerMap']['platform-messaging']['class']);
            }
            foreach ([
                IBrokerTopology::class => RabbitMqTopology::class,
                IBrokerPublisher::class => RabbitMqPublisher::class,
                IBrokerReceiver::class => RabbitMqReceiver::class,
            ] as $port => $adapter) {
                if ($configured) {
                    $instance = Yii::$container->get($port);
                    self::assertInstanceOf($adapter, $instance);
                    if ($instance instanceof IBrokerReceiver) {
                        self::assertNotSame($instance, Yii::$container->get($port));
                        $instance->close();
                    }
                } else {
                    try {
                        Yii::$container->get($port);
                        self::fail('Expected missing broker configuration.');
                    } catch (BrokerTransportException $exception) {
                        self::assertSame(BrokerTransportErrorCode::CONFIGURATION_INVALID, $exception->errorCode);
                        self::assertSame('configuration_invalid', $exception->getMessage());
                        self::assertNull($exception->getPrevious());
                    }
                }
            }
            if ($configured) {
                self::assertInstanceOf(DeclareMessagingTopologyHandler::class, Yii::$container->get(DeclareMessagingTopologyHandler::class));
            }
        } finally {
            Yii::$container = $originalContainer;
            $_ENV = $originalEnvironment;
        }
    }

    /** @dataProvider declarationOutcomes */
    public function testConsoleDeclarationDelegatesAndRendersSafeOutcome(?BrokerTransportErrorCode $failure): void
    {
        $topology = $this->createMock(IBrokerTopology::class);
        $expectation = $topology->expects($failure === null ? self::exactly(2) : self::once())->method('declare');
        if ($failure !== null) {
            $expectation->willThrowException(new BrokerTransportException($failure));
        }
        $controller = $this->getMockBuilder(MessagingController::class)
            ->setConstructorArgs(['platform-messaging', Yii::$app, new DeclareMessagingTopologyHandler($topology)])
            ->onlyMethods(['stdout', 'stderr'])
            ->getMock();
        if ($failure === null) {
            $controller->expects(self::exactly(2))->method('stdout')->with("Messaging topology declared.\n");
            $controller->expects(self::never())->method('stderr');
            self::assertSame(ExitCode::OK, $controller->actionDeclare());
            self::assertSame(ExitCode::OK, $controller->actionDeclare());
        } else {
            $controller->expects(self::never())->method('stdout');
            $controller->expects(self::once())->method('stderr')->with('Messaging topology declaration failed: ' . $failure->value . ".\n");
            self::assertSame(ExitCode::UNSPECIFIED_ERROR, $controller->actionDeclare());
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function configurations(): iterable
    {
        foreach (['web', 'console'] as $application) {
            yield $application . '-configured' => [$application, true];
            yield $application . '-missing' => [$application, false];
        }
    }

    /** @return iterable<string, array{BrokerTransportErrorCode|null}> */
    public static function declarationOutcomes(): iterable
    {
        yield 'success' => [null];
        yield 'mismatch' => [BrokerTransportErrorCode::TOPOLOGY_MISMATCH];
        yield 'connection failure' => [BrokerTransportErrorCode::CONNECTION_FAILURE];
    }
}
