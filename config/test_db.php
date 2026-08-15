<?php

declare(strict_types=1);

$db = require __DIR__ . '/db.php';
// test database! Important not to run tests on production or development databases
$db['dsn']      = 'pgsql:host=localhost;dbname=ideakit_test';
$db['username'] = $_ENV['DB_USERNAME'] ?? 'ideakit';
$db['password'] = $_ENV['DB_PASSWORD'] ?? 'local-development-only';

return $db;
