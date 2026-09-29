<?php

declare(strict_types=1);

if (getenv('APP_ENV') !== 'test'
    || !is_string(getenv('TEST_DB_DSN'))
    || !preg_match('/^pgsql:.*(?:^|;)dbname=ideakit_test(?:;|$)/', getenv('TEST_DB_DSN'))
) {
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

exit(tests\fixtures\platform\RuntimeTestScenario::run($argv[1] ?? ''));
