<?php

declare(strict_types=1);

use modules\telegram\presentation\controller\WebhookController;
use yii\web\Application;
use yii\web\ForbiddenHttpException;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
define('YII_ENV', $argv[1]);
define('YII_DEBUG', $argv[2] === 'true');
require dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

$mode = $argv[3];
$runtime = $argv[4];
$_ENV = [
    'APP_NAME' => 'Webhook test', 'APP_ENV' => YII_ENV,
    'APP_DEBUG' => YII_DEBUG ? 'true' : 'false', 'DEBUG_LVL' => '3',
    'ADMIN_EMAIL' => 'admin@example.test', 'SENDER_EMAIL' => 'sender@example.test',
    'SENDER_NAME' => 'Synthetic', 'COOKIE_VALIDATION_KEY' => 'synthetic-cookie-key',
    'DB_DSN' => 'pgsql:host=127.0.0.1;port=1;dbname=ideakit_test',
    'TEST_DB_DSN' => 'pgsql:host=127.0.0.1;port=1;dbname=ideakit_test',
    'DB_USERNAME' => 'synthetic', 'DB_PASSWORD' => 'synthetic',
];
$_SERVER = [
    'REQUEST_URI' => $mode === 'ordinary' ? '/docs' : '/telegram/webhook',
    'REQUEST_METHOD' => 'POST', 'QUERY_STRING' => '',
    'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => dirname(__DIR__, 4) . '/web/index.php',
    'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost',
    'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'synthetic-private-header',
];

$config = require dirname(__DIR__, 3) . '/config/telegram-webhook.php';
$config['runtimePath'] = $runtime;
$config['components']['log']['targets'][0]['logFile'] = $runtime . '/request.log';

if (in_array($mode, ['ordinary', 'composition'], true)) {
    $base = (static fn (): array => require dirname(__DIR__, 4) . '/config/web.php')();
    echo json_encode([
        'debug' => isset($config['modules']['debug']) && in_array('debug', $config['bootstrap'], true),
        'gii' => isset($config['modules']['gii']) && in_array('gii', $config['bootstrap'], true),
        'production_active' => isset($base['modules']['telegram'])
            && Yii::$container->has(modules\telegram\application\port\IAcceptTelegramUpdate::class),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

$config['container']['definitions'][WebhookController::class] = static function () use ($mode): never {
    $previous = new RuntimeException('synthetic-private-previous');
    throw $mode === 'http'
        ? new ForbiddenHttpException('synthetic-private-message', 0, $previous)
        : new RuntimeException('synthetic-private-message', 0, $previous);
};
$application = new Application($config);
$application->request->setRawBody('{"update_id":7,"callback_query":{"data":"synthetic-private-payload"}}');
$application->run();
