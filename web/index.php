<?php

require __DIR__ . '/../vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
} catch (Dotenv\Exception\InvalidPathException $e) {
    throw $e;
}

defined('YII_DEBUG') or define('YII_DEBUG', $_ENV['APP_DEBUG'] ?? true);
defined('YII_ENV') or define('YII_ENV', $_ENV['APP_ENV'] ?? 'dev');

require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@core', dirname(__DIR__) . '/core');
Yii::setAlias('@modules', dirname(__DIR__) . '/modules');

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
