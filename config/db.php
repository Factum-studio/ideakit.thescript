<?php

declare(strict_types=1);

return [
    'class'     => 'yii\db\Connection',
    'dsn'       => $_ENV['DB_DSN'],
    'username'  => $_ENV['DB_USERNAME'],
    'password'  => $_ENV['DB_PASSWORD'],
    'charset'   => 'utf8',
];
