<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if (!is_string($appEnv) || $appEnv === '') {
    $appEnv = 'dev';
}

$appDebugValue = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG');
if ($appDebugValue === false || $appDebugValue === null || $appDebugValue === '') {
    $appDebugValue = 'false';
}
if (!is_string($appDebugValue)) {
    throw new RuntimeException('APP_DEBUG must be either "true" or "false".');
}

$appDebug = filter_var($appDebugValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($appDebug === null) {
    throw new RuntimeException('APP_DEBUG must be either "true" or "false".');
}

defined('YII_DEBUG') or define('YII_DEBUG', $appDebug);
defined('YII_ENV') or define('YII_ENV', $appEnv);

require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@core', dirname(__DIR__) . '/core');
Yii::setAlias('@modules', dirname(__DIR__) . '/modules');

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
