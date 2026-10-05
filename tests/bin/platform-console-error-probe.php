<?php

declare(strict_types=1);

use tests\fixtures\platform\ConsoleFailureProbeController;
use tests\fixtures\platform\CriticalWorkerFailureProbe;
use modules\platform\application\handler\RunCriticalWorkerHandler;
use modules\platform\application\enum\BrokerTransportErrorCode;
use yii\console\Application;
use yii\log\FileTarget;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
define('YII_ENV', getenv('APP_ENV'));
define('YII_DEBUG', getenv('APP_DEBUG') === 'true');
require $root . '/vendor/yiisoft/yii2/Yii.php';

$runtime = getenv('CONSOLE_TEST_RUNTIME');
if (!is_string($runtime) || !is_dir($runtime) || !str_starts_with($runtime, sys_get_temp_dir() . '/ideakit-console-')) {
    exit(2);
}
$scenario = getenv('CONSOLE_TEST_SCENARIO');
$targetFactory = static function ($container, $params, array $config) use ($runtime): FileTarget {
    $config['logFile'] = $runtime . '/console.log';
    $config['logVars'] = [];

    return new FileTarget($config);
};
Yii::$container->set(FileTarget::class, $targetFactory);
$config = require $root . '/config/console.php';
$config['runtimePath'] = $runtime;
$config['controllerMap']['probe'] = ConsoleFailureProbeController::class;
if ($scenario === 'worker-runtime-failure') {
    Yii::$container->set(RunCriticalWorkerHandler::class, static fn (): RunCriticalWorkerHandler => CriticalWorkerFailureProbe::handler(Yii::$app->getLog()));
}
if ($scenario === 'worker-topology-failure') {
    Yii::$container->set(RunCriticalWorkerHandler::class, static fn (): RunCriticalWorkerHandler => CriticalWorkerFailureProbe::handler(
        Yii::$app->getLog(),
        BrokerTransportErrorCode::TOPOLOGY_MISMATCH,
    ));
}
if ($scenario === 'di') {
    Yii::$container->set(ConsoleFailureProbeController::class, static function (): never {
        throw new RuntimeException('synthetic-sensitive-di', 0, new RuntimeException('synthetic-sensitive-inner'));
    });
}
if ($scenario === 'log-failure') {
    $config['components']['log']['targets'] = [
        new class (['logFile' => $runtime . '/failed.log', 'logVars' => []]) extends FileTarget {
            public function export(): void
            {
                throw new RuntimeException('synthetic-sensitive-log');
            }
        },
        ['class' => FileTarget::class, 'levels' => ['error', 'warning'], 'logVars' => []],
    ];
}
$application = new Application($config);
exit($application->run());
