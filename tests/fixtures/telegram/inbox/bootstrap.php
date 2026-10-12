<?php

declare(strict_types=1);

use modules\telegram\application\handler\AcceptTelegramUpdateHandler;
use modules\telegram\application\port\IAcceptTelegramUpdate;
use tests\fixtures\platform\PlatformTestEnvironment;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
define('YII_ENV', 'test');
define('YII_DEBUG', false);
require dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

$_ENV = PlatformTestEnvironment::applicationEnvironment();
$_ENV['COOKIE_VALIDATION_KEY'] = 'synthetic-cookie-key';
$_SERVER = [
    'REQUEST_URI' => '/docs/swagger', 'REQUEST_METHOD' => 'GET', 'QUERY_STRING' => '',
    'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => dirname(__DIR__, 4) . '/web/index.php',
    'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost',
];
try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['web', 'console'], true)) {
        throw new RuntimeException('invalid_probe');
    }
    $config = require dirname(__DIR__, 4) . '/config/' . $mode . '.php';
    $app = $mode === 'web' ? new yii\web\Application($config) : new yii\console\Application($config);
    if (!Yii::$container->get(IAcceptTelegramUpdate::class) instanceof AcceptTelegramUpdateHandler || $app->db->isActive) {
        throw new RuntimeException('invalid_binding');
    }
    if ($app instanceof yii\web\Application) {
        $app->trigger(yii\base\Application::EVENT_BEFORE_REQUEST);
        $response = $app->handleRequest($app->getRequest());
        if ($response->statusCode !== 200 || $app->db->pdo !== null) {
            throw new RuntimeException('invalid_web_bootstrap');
        }
    }
    echo 'acceptance_bootstrap_ok';
} catch (Throwable) {
    fwrite(STDERR, "acceptance_bootstrap_failed\n");
    exit(1);
}
