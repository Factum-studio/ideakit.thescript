<?php

declare(strict_types=1);

namespace tests\fixtures\platform;

use RuntimeException;
use yii\db\Connection;

final class CriticalWorkerTestEnvironment
{
    public static function configure(): string
    {
        $environment = PlatformTestEnvironment::workerEnvironment();
        $scenario = PlatformTestEnvironment::required('WORKER_TEST_SCENARIO');
        if (!in_array($scenario, [
                'normal', 'crash_after_commit', 'terminal', 'unexpected',
                'dirty', 'hard_timeout', 'signal_after_commit',
            ], true)
        ) {
            throw new RuntimeException('configuration_invalid');
        }
        foreach (array_keys(PlatformTestEnvironment::runtimeDefaults()) as $key) {
            if (array_key_exists($key, $_ENV) || getenv($key) !== false) {
                $environment[$key] = PlatformTestEnvironment::required($key);
            }
        }
        $_ENV = array_replace($_ENV, $environment);

        return $scenario;
    }

    public static function assertDatabase(Connection $db): void
    {
        if ($db->driverName !== 'pgsql'
            || $db->createCommand('SELECT current_database()')->queryScalar() !== 'ideakit_test'
        ) {
            throw new RuntimeException('configuration_invalid');
        }
    }
}
