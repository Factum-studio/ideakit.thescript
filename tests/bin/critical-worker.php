<?php

declare(strict_types=1);

use modules\platform\application\dto\BackgroundCommandRegistration;
use modules\platform\application\port\IOutboxWriter;
use modules\platform\application\route\BackgroundCommandRegistry;
use modules\platform\application\route\OutboxRouteRegistry;
use tests\fixtures\platform\CriticalWorkerTestEnvironment;
use tests\fixtures\platform\PersistedTestCommandHandler;
use yii\console\Application;
use yii\db\Connection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

try {
    $scenario = CriticalWorkerTestEnvironment::configure();
    defined('YII_DEBUG') or define('YII_DEBUG', false);
    defined('YII_ENV') or define('YII_ENV', 'test');
    $application = new Application(require dirname(__DIR__, 2) . '/config/console.php');
    $db = $application->get('db');
    if (!$db instanceof Connection) {
        throw new RuntimeException('configuration_invalid');
    }
    CriticalWorkerTestEnvironment::assertDatabase($db);
    $handler = new PersistedTestCommandHandler($db, Yii::$container->get(IOutboxWriter::class), $scenario);
    Yii::$container->set(BackgroundCommandRegistry::class, static function () use ($handler): BackgroundCommandRegistry {
        return new BackgroundCommandRegistry([
            new BackgroundCommandRegistration('telegram.update.received', '1.0', $handler),
        ], Yii::$container->get(OutboxRouteRegistry::class));
    });
} catch (Throwable) {
    fwrite(STDERR, "configuration_invalid\n");

    exit(2);
}

exit($application->run());
