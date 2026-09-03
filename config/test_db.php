<?php

declare(strict_types=1);

$db = require __DIR__ . '/db.php';

$db['dsn'] = $_ENV['TEST_DB_DSN']
    ?? 'pgsql:host=localhost;port=5432;dbname=ideakit_test';
$db['username'] = $_ENV['TEST_DB_USERNAME'] ?? 'ideakit';
$db['password'] = $_ENV['TEST_DB_PASSWORD'] ?? 'local-development-only';

return $db;
