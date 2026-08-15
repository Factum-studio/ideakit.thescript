<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Устанавливаем значения по умолчанию, если они не заданы
$_ENV['ADMIN_EMAIL']    = $_ENV['ADMIN_EMAIL'] ?? 'test-admin@example.com';
$_ENV['SENDER_EMAIL']   = $_ENV['SENDER_EMAIL'] ?? 'test-sender@example.com';
$_ENV['SENDER_NAME']    = $_ENV['SENDER_NAME'] ?? 'Test Sender';
$_ENV['COOKIE_VALIDATION_KEY'] = $_ENV['COOKIE_VALIDATION_KEY'] ?? 'test-validation-key';
$_ENV['JWT_SECRET']     = $_ENV['JWT_SECRET'] ?? 'test-secret-key';
$_ENV['JWT_TTL']        = $_ENV['JWT_TTL'] ?? 3600;

require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require __DIR__ .'/../vendor/autoload.php';
