<?php

declare(strict_types=1);

namespace tests\fixtures\platform;

use RuntimeException;
use tests\integration\modules\platform\infrastructure\rabbitmq\RabbitMqTestEnvironment;
use yii\db\Connection;

final class CriticalWorkerTestEnvironment
{
    public static function configure(): string
    {
        $dsn = getenv('TEST_DB_DSN');
        $scenario = getenv('WORKER_TEST_SCENARIO');
        if (getenv('APP_ENV') !== 'test'
            || !is_string($dsn)
            || preg_match('/\Apgsql:host=postgres;port=5432;dbname=ideakit_test\z/', $dsn) !== 1
            || !is_string($scenario)
            || !in_array($scenario, [
                'normal', 'crash_after_commit', 'terminal', 'unexpected',
                'dirty', 'hard_timeout', 'signal_after_commit',
            ], true)
        ) {
            throw new RuntimeException('configuration_invalid');
        }
        foreach (['HOST', 'PORT', 'USER', 'PASSWORD', 'VHOST'] as $field) {
            $value = getenv('TEST_RABBITMQ_' . $field);
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('configuration_invalid');
            }
            $_ENV['TEST_RABBITMQ_' . $field] = $value;
            $_ENV['RABBITMQ_' . $field] = $value;
        }
        RabbitMqTestEnvironment::factory();
        $_ENV['APP_ENV'] = 'test';
        $_ENV['APP_DEBUG'] = 'false';
        $_ENV['DB_DSN'] = $dsn;
        $_ENV['DB_USERNAME'] = self::credential('TEST_DB_USERNAME');
        $_ENV['DB_PASSWORD'] = self::credential('TEST_DB_PASSWORD');

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

    private static function credential(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('configuration_invalid');
        }

        return $value;
    }
}
