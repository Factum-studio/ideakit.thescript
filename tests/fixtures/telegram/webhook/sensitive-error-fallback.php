<?php

declare(strict_types=1);

use core\infrastructure\handler\JsonErrorHandler;
use yii\log\Target;
use yii\web\Application;
use yii\web\Response;

define('YII_DEBUG', getenv('APP_DEBUG') === 'true');
define('YII_ENV', getenv('APP_ENV'));
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

$_SERVER = [
    'REQUEST_URI' => '/telegram/webhook?synthetic-sensitive-webhook-detail',
    'REQUEST_METHOD' => ($argv[2] ?? '') === 'HEAD' ? 'HEAD' : 'POST',
    'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'synthetic-sensitive-webhook-detail',
];
$_ENV['DEBUG_LVL'] = 3;
new Application([
    'id' => 'sensitive-fallback-test',
    'basePath' => dirname(__DIR__, 4),
    'components' => [
        'request' => [
            'cookieValidationKey' => 'synthetic-key', 'scriptUrl' => '/index.php',
            'scriptFile' => dirname(__DIR__, 4) . '/web/index.php',
        ],
        'log' => ['targets' => [new class (['logVars' => []]) extends Target {
            public function export(): void
            {
                if (($GLOBALS['argv'][1] ?? '') === 'logger') {
                    throw new RuntimeException('synthetic-sensitive-webhook-detail');
                }
            }
        }]],
    ],
]);
Yii::$app->getLog();
Yii::getLogger()->messages = [];
if (($argv[1] ?? '') === 'formatter') {
    Yii::$app->response->on(Response::EVENT_BEFORE_SEND, static function (): void {
        echo 'synthetic-sensitive-webhook-detail';
        throw new RuntimeException('synthetic-sensitive-webhook-detail');
    });
}
ob_start();
(new JsonErrorHandler([
    'sensitivePaths' => ['/telegram/webhook'],
    'sensitiveErrorMessages' => [500 => 'webhook_internal_error'],
]))->handleException(new RuntimeException(
    'synthetic-sensitive-webhook-detail',
    0,
    new RuntimeException('synthetic-sensitive-webhook-detail'),
));
