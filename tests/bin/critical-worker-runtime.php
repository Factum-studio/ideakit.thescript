<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
try {
    tests\fixtures\platform\PlatformTestEnvironment::databaseEnvironment();
} catch (Throwable) {
    fwrite(STDERR, "configuration_invalid\n");
    exit(2);
}
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

exit(tests\fixtures\platform\RuntimeTestScenario::run($argv[1] ?? ''));
