<?php

declare(strict_types=1);

namespace tests\fixtures\platform;

use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionFactory;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use yii\db\Connection;

final class PlatformTestEnvironment
{
    public static function required(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('configuration_invalid');
        }

        return $value;
    }

    /** @return array{APP_ENV: string, TEST_DB_DSN: string, TEST_DB_USERNAME: string, TEST_DB_PASSWORD: string, DB_DSN: string, DB_USERNAME: string, DB_PASSWORD: string} */
    public static function databaseEnvironment(): array
    {
        self::assertTestMode();
        $dsn = self::required('TEST_DB_DSN');
        if (preg_match('/\Apgsql:host=(?:postgres|localhost|127\.0\.0\.1);port=([0-9]{1,5});dbname=ideakit_test\z/', $dsn, $matches) !== 1
            || (int) $matches[1] < 1 || (int) $matches[1] > 65535
        ) {
            throw new RuntimeException('configuration_invalid');
        }
        $username = self::required('TEST_DB_USERNAME');
        $password = self::required('TEST_DB_PASSWORD');

        return [
            'APP_ENV' => 'test',
            'TEST_DB_DSN' => $dsn, 'TEST_DB_USERNAME' => $username, 'TEST_DB_PASSWORD' => $password,
            'DB_DSN' => $dsn, 'DB_USERNAME' => $username, 'DB_PASSWORD' => $password,
        ];
    }

    /** @return array<string, string> */
    public static function brokerEnvironment(): array
    {
        self::assertTestMode();
        $environment = self::runtimeDefaults();
        foreach (['HOST', 'PORT', 'USER', 'PASSWORD', 'VHOST'] as $field) {
            $value = self::required('TEST_RABBITMQ_' . $field);
            $environment['TEST_RABBITMQ_' . $field] = $value;
            $environment['RABBITMQ_' . $field] = $value;
        }
        if ($environment['RABBITMQ_VHOST'] !== 'ideakit_transport_test'
            || !in_array($environment['RABBITMQ_HOST'], ['rabbitmq-test', '127.0.0.1', 'localhost'], true)
            || ($environment['RABBITMQ_HOST'] === 'rabbitmq-test' && $environment['RABBITMQ_PORT'] !== '5672')
            || ($environment['RABBITMQ_HOST'] !== 'rabbitmq-test' && $environment['RABBITMQ_PORT'] === '5672')
        ) {
            throw new RuntimeException('configuration_invalid');
        }
        try {
            RabbitMqConnectionConfig::fromEnvironment($environment);
        } catch (Throwable) {
            throw new RuntimeException('configuration_invalid');
        }

        return $environment;
    }

    /** @return array<string, string> */
    public static function workerEnvironment(): array
    {
        return array_merge(self::applicationEnvironment(), self::databaseEnvironment(), self::brokerEnvironment());
    }

    /** @return array<string, string> */
    public static function applicationEnvironment(): array
    {
        return array_merge(self::runtimeDefaults(), [
            'APP_NAME' => 'IDEAKIT', 'APP_ENV' => 'test', 'APP_DEBUG' => 'false',
            'ADMIN_EMAIL' => 'admin@example.test', 'SENDER_EMAIL' => 'no-reply@example.test', 'SENDER_NAME' => 'IDEAKIT',
            'DB_DSN' => 'pgsql:host=127.0.0.1;port=1;dbname=ideakit_test',
            'DB_USERNAME' => 'synthetic', 'DB_PASSWORD' => 'synthetic-test-only',
            'RABBITMQ_HOST' => '127.0.0.1', 'RABBITMQ_PORT' => '1',
            'RABBITMQ_USER' => 'synthetic', 'RABBITMQ_PASSWORD' => 'synthetic-test-only',
            'RABBITMQ_VHOST' => 'ideakit_transport_test',
        ]);
    }

    /** @return array<string, string> */
    public static function runtimeDefaults(): array
    {
        return [
            'RABBITMQ_CONNECTION_TIMEOUT' => '3', 'RABBITMQ_CHANNEL_RPC_TIMEOUT' => '5',
            'RABBITMQ_HEARTBEAT' => '10', 'RABBITMQ_READ_TIMEOUT' => '25', 'RABBITMQ_WRITE_TIMEOUT' => '25',
            'RABBITMQ_CONFIRM_TIMEOUT' => '5', 'RABBITMQ_CONSUMER_POLL_TIMEOUT' => '1',
            'OUTBOX_RELAY_LIMIT' => '10', 'OUTBOX_RELAY_MAX_ATTEMPTS' => '5', 'OUTBOX_RELAY_LEASE_SECONDS' => '600',
            'OUTBOX_RELAY_RETRY_BASE_SECONDS' => '15', 'OUTBOX_RELAY_RETRY_MAX_SECONDS' => '900',
            'CRITICAL_WORKER_MAX_MESSAGES' => '1000', 'CRITICAL_WORKER_MAX_RUNTIME_SECONDS' => '3600',
            'CRITICAL_WORKER_HANDLER_TIMEOUT_SECONDS' => '4', 'CRITICAL_WORKER_BROKER_OPERATION_TIMEOUT_SECONDS' => '10',
            'CRITICAL_WORKER_RECEIVE_TIMEOUT_SECONDS' => '1', 'CRITICAL_WORKER_MEMORY_LIMIT_MIB' => '256',
            'CRITICAL_WORKER_SOFT_MEMORY_LIMIT_MIB' => '192', 'CRITICAL_WORKER_SHUTDOWN_TIMEOUT_SECONDS' => '10',
        ];
    }

    public static function assertRuntimeCapabilities(): void
    {
        self::checkRuntime();
        $process = new Process([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; try { tests\\fixtures\\platform\\PlatformTestEnvironment::checkRuntime(); } catch (Throwable) { exit(1); }',
        ], dirname(__DIR__, 3), null, null, 5.0);
        try {
            $process->run();
            if (!$process->isSuccessful()) {
                throw new RuntimeException('runtime_capability_unavailable');
            }
        } catch (Throwable) {
            throw new RuntimeException('runtime_capability_unavailable');
        }
    }

    public static function checkRuntime(): void
    {
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') {
            throw new RuntimeException('runtime_capability_unavailable');
        }
        foreach (['pdo_pgsql', 'mbstring', 'sockets', 'pcntl', 'posix'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new RuntimeException('runtime_capability_unavailable');
            }
        }
        foreach (['pcntl_alarm', 'pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals', 'pcntl_sigprocmask', 'posix_kill'] as $function) {
            if (!in_array($function, get_defined_functions()['internal'], true)) {
                throw new RuntimeException('runtime_capability_unavailable');
            }
        }
        if (!posix_kill(getmypid(), 0)) {
            throw new RuntimeException('runtime_capability_unavailable');
        }
    }

    public static function preflight(): void
    {
        self::assertRuntimeCapabilities();
        $environment = self::workerEnvironment();
        $db = new Connection([
            'dsn' => $environment['DB_DSN'], 'username' => $environment['DB_USERNAME'], 'password' => $environment['DB_PASSWORD'],
        ]);
        try {
            CriticalWorkerTestEnvironment::assertDatabase($db);
        } catch (Throwable) {
            throw new RuntimeException('test_database_unavailable');
        } finally {
            $db->close();
        }
        try {
            $connection = (new RabbitMqConnectionFactory(RabbitMqConnectionConfig::fromEnvironment($environment)))->connect();
            $connection->close();
        } catch (Throwable) {
            throw new RuntimeException('test_broker_unavailable');
        }
    }

    private static function assertTestMode(): void
    {
        if (self::required('APP_ENV') !== 'test') {
            throw new RuntimeException('configuration_invalid');
        }
    }
}
