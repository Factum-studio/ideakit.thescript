<?php

declare(strict_types=1);

use modules\telegram\application\port\IAcceptTelegramUpdate;
use tests\fixtures\telegram\webhook\RecordingTelegramUpdateAcceptor;
use modules\telegram\infrastructure\config\TelegramWebhookConfig;

$config = require dirname(__DIR__, 2) . '/config/web.php';
$configure = require dirname(__DIR__, 2) . '/config/telegram_webhook.php';
$config = $configure($config);
$config['components']['db'] = require dirname(__DIR__, 2) . '/config/test_db.php';
$config['components']['log']['targets'] = [[
    'class' => yii\log\FileTarget::class,
    'logFile' => '@runtime/logs/telegram-webhook-test.log',
    'levels' => ['error', 'warning'],
    'logVars' => [],
    'prefix' => static fn (): string => '',
]];
$config['container']['singletons'][IAcceptTelegramUpdate::class] = static fn () => new RecordingTelegramUpdateAcceptor();
$config['container']['definitions'][TelegramWebhookConfig::class] = static fn () => new TelegramWebhookConfig('synthetic-bot', 'synthetic-webhook-secret');

return $config;
