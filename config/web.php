<?php

use core\security\YiiIdentity;
use yii\symfonymailer\Mailer;

$params         = require __DIR__ . '/params.php';
$db             = require __DIR__ . '/db.php';
$modules        = require __DIR__ . '/modules.php';
$ignoreConfig   = require __DIR__ . '/ignore_routes.php';

$config = [
    'id' => $_ENV['APP_NAME'],
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'core\presentation\controller',
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower'    => '@vendor/bower-asset',
        '@npm'      => '@vendor/npm-asset',
        '@core'     => dirname(__DIR__) . '/core',
        '@modules'  => dirname(__DIR__) . '/modules',
    ],
    'on beforeRequest' => function () {
        global $ignoreConfig;
        $request = Yii::$app->request;
        $currentPath = $request->getPathInfo();

        if (in_array($currentPath, $ignoreConfig['ignoreRoutes']['exact'])) return;
        foreach ($ignoreConfig['ignoreRoutes']['startsWith'] as $prefix)
            if (str_starts_with($currentPath, $prefix)) return;
        foreach ($ignoreConfig['ignoreRoutes']['regex'] as $pattern)
            if (preg_match($pattern, $currentPath)) return;
    },
    'modules' => $modules,
    'components' => [
        'request' => [
            'cookieValidationKey' => $_ENV['COOKIE_VALIDATION_KEY'],
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'response' => [
            'format'    => yii\web\Response::FORMAT_JSON,
            'charset'   => 'UTF-8',
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'user' => [
            'identityClass'     => YiiIdentity::class,
            'enableAutoLogin'   => false,
            'enableSession'     => false,
        ],
        'mailer' => [
            'class'     => Mailer::class,
            'viewPath'  => '@app/mail',
            'useFileTransport' => true,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 1 : 0,
            'targets' => [
                [
                    'class'     => 'yii\log\FileTarget',
                    'levels'    => ['error', 'warning'],
                    'logVars'   => [],
                    'except' => [
                        'yii\web\HttpException:404',
                    ],
                ],

            ],
        ],
        'db' => $db,
        'urlManager' => [
            'enablePrettyUrl'   => true,
            'showScriptName'    => false,
            'rules' =>  array_merge(
                [
                    // Дефолтный маршрут для OPTIONS (CORS)
                    'OPTIONS <any:.*>' => 'site/options',
                ]
            ),
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
    ];

    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
    ];
}

return $config;
