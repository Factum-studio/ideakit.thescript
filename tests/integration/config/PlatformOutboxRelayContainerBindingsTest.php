<?php

declare(strict_types=1);

namespace tests\integration\config;

use tests\fixtures\platform\PlatformTestEnvironment;
use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\handler\RelayOutboxHandler;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IBrokerPublisher;
use modules\platform\application\port\IOutboxRelayStore;
use modules\platform\application\port\IOutboxWriter;
use modules\platform\infrastructure\db\DbOutboxRelayStore;
use modules\platform\infrastructure\random\SecureRetryJitter;
use modules\platform\presentation\console\OutboxRelayController;
use Yii;
use yii\db\Connection;
use yii\di\Container;

final class PlatformOutboxRelayContainerBindingsTest extends Unit
{
    /** @dataProvider configurations */
    public function testLazyBindingsShareWriterDatabase(string $application): void
    {
        $connection = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame('ideakit_test', $connection->createCommand('SELECT current_database()')->queryScalar());
        self::assertSame(0, (int) $connection->createCommand('SELECT count(*) FROM {{%outbox_messages}}')->queryScalar());
        $connection->close();
        $originalContainer = Yii::$container;
        $originalEnvironment = $_ENV;
        Yii::$container = new Container();
        $_ENV = array_replace($_ENV, PlatformTestEnvironment::applicationEnvironment());
        try {
            $config = require dirname(__DIR__, 3) . '/config/' . $application . '.php';
            self::assertIsArray($config);
            if ($application === 'console') {
                self::assertSame(OutboxRelayController::class, $config['controllerMap']['platform-outbox']['class']);
            }
            self::assertInstanceOf(IOutboxWriter::class, Yii::$container->get(IOutboxWriter::class));
            $store = Yii::$container->get(IOutboxRelayStore::class);
            self::assertInstanceOf(DbOutboxRelayStore::class, $store);
            self::assertInstanceOf(RelayOutboxHandler::class, Yii::$container->get(RelayOutboxHandler::class));
            self::assertFalse($connection->isActive);
            $transaction = $connection->beginTransaction();
            try {
                $store->claimNext(new OutboxRelaySettings(5, 600, 15, 900));
                self::fail('Expected shared caller transaction rejection.');
            } catch (OutboxRelayException $exception) {
                self::assertSame(OutboxRelayError::TRANSACTION_ALREADY_ACTIVE, $exception->error);
            } finally {
                $transaction->rollBack();
            }
        } finally {
            Yii::$container = $originalContainer;
            $_ENV = $originalEnvironment;
        }
    }

    /** @dataProvider invalidLimits */
    public function testInvalidLimitDoesNotClaim(mixed $limit): void
    {
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::never())->method('claimNext');
        $controller = $this->controller($store);
        $controller->limit = $limit;
        $controller->expects(self::never())->method('stdout');
        $controller->expects(self::once())->method('stderr')->with("Invalid relay limit.\n");
        self::assertSame(2, $controller->actionRelay());
    }

    /** @dataProvider validLimits */
    public function testConsoleReportsCountersAndUsesConfiguredDefault(?string $limit, int $expected): void
    {
        $store = $this->createMock(IOutboxRelayStore::class);
        $claim = new OutboxRelayClaim(
            '01890f4d-3c2a-7f48-8c0b-123456789be1',
            'synthetic-lease',
            1,
            true,
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-01T00:10:00Z'),
            null,
            OutboxRelayError::INVALID_MESSAGE,
        );
        $store->expects(self::exactly($expected))->method('claimNext')->willReturn($claim);
        $store->expects(self::exactly($expected))->method('finish')->willReturn(true);
        $controller = $this->controller($store);
        $controller->limit = $limit;
        $controller->expects(self::once())->method('stdout')->with("claimed=$expected delivered=0 retry_scheduled=0 failed=$expected lease_lost=0\n");
        $controller->expects(self::never())->method('stderr');
        self::assertSame(0, $controller->actionRelay());
    }

    public function testConsoleOperationalFailureIsSafe(): void
    {
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willThrowException(new OutboxRelayException(
            OutboxRelayError::PERSISTENCE_FAILURE,
            new \RuntimeException('synthetic-private-detail'),
            SafeCauseCode::PERSISTENCE,
        ));
        $controller = $this->controller($store);
        $controller->expects(self::never())->method('stdout');
        $controller->expects(self::once())->method('stderr')->with("Outbox relay failed: persistence_failure cause_code=PERSISTENCE.\n");
        self::assertSame(1, $controller->actionRelay());
    }

    /** @return OutboxRelayController&\PHPUnit\Framework\MockObject\MockObject */
    private function controller(IOutboxRelayStore $store): OutboxRelayController
    {
        $settings = new OutboxRelaySettings(5, 600, 15, 900);
        return $this->getMockBuilder(OutboxRelayController::class)
            ->setConstructorArgs(['platform-outbox', Yii::$app, new RelayOutboxHandler(
                $store,
                $this->createMock(IBrokerPublisher::class),
                $settings,
                new OutboxRetryPolicy($settings, new SecureRetryJitter()),
            ), 7])->onlyMethods(['stdout', 'stderr'])->getMock();
    }

    /** @return iterable<string, array{string}> */
    public static function configurations(): iterable
    {
        yield 'web' => ['web'];
        yield 'console' => ['console'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLimits(): iterable
    {
        foreach (['abc', '1.5', '0', '-1', '101', '999999999999999999999', '', true, [], 10] as $index => $value) {
            yield 'invalid ' . $index => [$value];
        }
    }

    /** @return iterable<string, array{string|null, int}> */
    public static function validLimits(): iterable
    {
        yield 'default' => [null, 7];
        yield 'minimum' => ['1', 1];
        yield 'maximum' => ['100', 100];
    }
}
