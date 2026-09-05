<?php

declare(strict_types=1);

$params = require __DIR__ . '/params.php';
$db     = require __DIR__ . '/test_db.php';
$container = __DIR__ . '/container.php';

/**
 * Application configuration shared by all test types
 */
$config = [
    'id' => $_ENV['APP_NAME'].'-tests',
    'basePath' => dirname(__DIR__),
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'language' => 'en-US',
    'components' => [
        'db' => $db,
        'mailer' => [
            'class' => \yii\symfonymailer\Mailer::class,
            'viewPath' => '@app/mail',
            // send all mails to a file by default.
            'useFileTransport' => true,
            'messageClass' => 'yii\symfonymailer\Message',
        ],
        'assetManager' => [
            'basePath' => __DIR__ . '/../web/assets',
        ],
        'urlManager' => [
            'showScriptName' => true,
        ],
//        'user' => [
//            'identityClass'   => 'app\models\User',
//            'enableAutoLogin' => false,
//            'enableSession'   => false,
//        ],
//        'cache' => [
//            'class' => 'yii\caching\DummyCache',
//        ],
        'request' => [
            'cookieValidationKey' => 'test',
            'enableCsrfValidation' => false,
            // but if you absolutely need it set cookie domain to localhost
            /*
            'csrfCookie' => [
                'domain' => 'localhost',
            ],
            */
        ],
    ],
    'params' => $params,
];

require $container;

return $config;
