<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use core\application\handler\ResolveUserIdentityHandler;
use core\application\port\ITransactionManager;
use modules\users\application\handler\MarkTelegramProfileBlockedHandler;
use modules\users\application\handler\ResolveTelegramIdentityHandler;
use modules\users\application\port\ITelegramIdentityProfileIdGenerator;
use modules\users\application\port\ITelegramIdentityProfileRepository;
use modules\users\application\port\ITransactionRunner;
use modules\users\application\port\IUserIdentityResolver;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\infrastructure\core\CoreUserIdentityResolver;
use modules\users\infrastructure\db\DbTransactionRunner;
use modules\users\infrastructure\identity\RamseyTelegramIdentityProfileIdGenerator;
use modules\users\infrastructure\repository\DbTelegramIdentityProfileRepository;
use Yii;
use yii\di\Container;

final class TelegramIdentityContainerBindingsTest extends Unit
{
    public function testTestApplicationUsesProductionBindings(): void
    {
        self::assertInstanceOf(
            ResolveUserIdentityHandler::class,
            Yii::$container->get(ResolveUserIdentityHandler::class),
        );
        self::assertInstanceOf(
            CoreUserIdentityResolver::class,
            Yii::$container->get(IUserIdentityResolver::class),
        );
        self::assertInstanceOf(
            DbTelegramIdentityProfileRepository::class,
            Yii::$container->get(ITelegramIdentityProfileRepository::class),
        );
        self::assertInstanceOf(
            DbTransactionRunner::class,
            Yii::$container->get(ITransactionRunner::class),
        );
        self::assertInstanceOf(
            ResolveTelegramIdentityHandler::class,
            Yii::$container->get(ResolveTelegramIdentityHandler::class),
        );
        self::assertInstanceOf(
            MarkTelegramProfileBlockedHandler::class,
            Yii::$container->get(MarkTelegramProfileBlockedHandler::class),
        );

        $generator = Yii::$container->get(ITelegramIdentityProfileIdGenerator::class);
        self::assertInstanceOf(RamseyTelegramIdentityProfileIdGenerator::class, $generator);
        self::assertInstanceOf(TelegramIdentityProfileId::class, $generator->generate());

        self::assertSame(
            Yii::$container->get(ITransactionManager::class),
            Yii::$container->get(ITransactionManager::class),
        );
    }

    public function testConsoleConfigurationLoadsProductionBindings(): void
    {
        $originalContainer = Yii::$container;
        Yii::$container = new Container();

        try {
            $config = require dirname(__DIR__, 3) . '/config/console.php';

            self::assertIsArray($config);
            self::assertInstanceOf(
                ResolveTelegramIdentityHandler::class,
                Yii::$container->get(ResolveTelegramIdentityHandler::class),
            );
            self::assertInstanceOf(
                MarkTelegramProfileBlockedHandler::class,
                Yii::$container->get(MarkTelegramProfileBlockedHandler::class),
            );
        } finally {
            Yii::$container = $originalContainer;
        }
    }
}
