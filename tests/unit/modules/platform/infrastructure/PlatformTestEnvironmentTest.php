<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure;

use Codeception\Test\Unit;
use RuntimeException;
use Symfony\Component\Process\Process;
use tests\fixtures\platform\CriticalWorkerTestEnvironment;
use tests\fixtures\platform\PlatformTestEnvironment;

final class PlatformTestEnvironmentTest extends Unit
{
    /** @var array<string, mixed> */
    private array $environment;

    protected function _before(): void
    {
        $this->environment = $_ENV;
        $_ENV = [
            'APP_ENV' => 'test',
            'TEST_DB_DSN' => 'pgsql:host=postgres;port=5432;dbname=ideakit_test',
            'TEST_DB_USERNAME' => 'synthetic', 'TEST_DB_PASSWORD' => 'synthetic-test-only',
            'TEST_RABBITMQ_HOST' => 'rabbitmq-test', 'TEST_RABBITMQ_PORT' => '5672',
            'TEST_RABBITMQ_USER' => 'synthetic', 'TEST_RABBITMQ_PASSWORD' => 'synthetic-test-only',
            'TEST_RABBITMQ_VHOST' => 'ideakit_transport_test',
        ];
    }

    protected function _after(): void
    {
        $_ENV = $this->environment;
    }

    public function testProcessValueAndExplicitOverrideHaveDefinedPrecedence(): void
    {
        $key = 'PLATFORM_SYNTHETIC_SETTING';
        $original = getenv($key);
        try {
            putenv($key . '=process');
            self::assertSame('process', PlatformTestEnvironment::required($key));
            $_ENV[$key] = 'fixture';
            self::assertSame('fixture', PlatformTestEnvironment::required($key));
            $_ENV[$key] = '';
            $this->expectExceptionMessage('configuration_invalid');
            PlatformTestEnvironment::required($key);
        } finally {
            $original === false ? putenv($key) : putenv($key . '=' . $original);
        }
    }

    public function testMissingTestSettingNeverFallsBackToWorkingSettings(): void
    {
        $_ENV['DB_PASSWORD'] = 'synthetic-working';
        $_ENV['TEST_DB_PASSWORD'] = '';
        $this->expectExceptionMessage('configuration_invalid');
        PlatformTestEnvironment::databaseEnvironment();
    }

    /** @dataProvider validDatabases */
    public function testWorkerAcceptsOnlyExplicitTestConnections(string $host, string $port): void
    {
        $_ENV['TEST_DB_DSN'] = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=ideakit_test';
        $_ENV['WORKER_TEST_SCENARIO'] = 'normal';
        $environment = PlatformTestEnvironment::workerEnvironment();
        self::assertTrue($environment['DB_DSN'] === $_ENV['TEST_DB_DSN']);
        self::assertTrue($environment['DB_PASSWORD'] === $environment['TEST_DB_PASSWORD']);
        self::assertTrue($environment['RABBITMQ_PASSWORD'] === $environment['TEST_RABBITMQ_PASSWORD']);
        self::assertSame('normal', CriticalWorkerTestEnvironment::configure());
    }

    /** @return iterable<string, array{string, string}> */
    public static function validDatabases(): iterable
    {
        yield 'docker' => ['postgres', '5432'];
        yield 'host IP' => ['127.0.0.1', '5432'];
        yield 'host name' => ['localhost', '15432'];
    }

    /** @dataProvider unsafeSettings */
    public function testUnsafeOrIncompleteSettingsFailWithoutDisclosure(string $key, mixed $value): void
    {
        $_ENV[$key] = $value;
        try {
            PlatformTestEnvironment::workerEnvironment();
            self::fail('Expected isolated test configuration rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame('configuration_invalid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function unsafeSettings(): iterable
    {
        foreach ([
            'pgsql:host=postgres;port=5432;dbname=ideakit',
            'pgsql:host=external.example.test;port=5432;dbname=ideakit_test',
            'pgsql:host=postgres;port=5432;dbname=ideakit_test;sslmode=disable',
            'pgsql:host=postgres;port=0;dbname=ideakit_test',
            'pgsql:host=postgres;port=65536;dbname=ideakit_test',
        ] as $index => $dsn) {
            yield 'dsn ' . $index => ['TEST_DB_DSN', $dsn];
        }
        yield 'mode' => ['APP_ENV', 'prod'];
        yield 'empty username' => ['TEST_DB_USERNAME', ''];
        yield 'nonstring credential' => ['TEST_DB_PASSWORD', 123];
        yield 'broker host' => ['TEST_RABBITMQ_HOST', 'rabbitmq'];
        yield 'broker vhost' => ['TEST_RABBITMQ_VHOST', 'ideakit'];
        yield 'broker port' => ['TEST_RABBITMQ_PORT', '0'];
        yield 'broker password' => ['TEST_RABBITMQ_PASSWORD', ''];
    }

    public function testChildrenReceiveFixtureValuesAndDeterministicLimits(): void
    {
        $_ENV['CRITICAL_WORKER_MAX_MESSAGES'] = '0';
        $_ENV['RABBITMQ_HEARTBEAT'] = '0';
        $_ENV['OUTBOX_RELAY_MAX_ATTEMPTS'] = '0';
        $environment = PlatformTestEnvironment::workerEnvironment();
        self::assertSame('1000', $environment['CRITICAL_WORKER_MAX_MESSAGES']);
        self::assertSame('10', $environment['RABBITMQ_HEARTBEAT']);
        self::assertSame('5', $environment['OUTBOX_RELAY_MAX_ATTEMPTS']);
        $original = getenv('TEST_DB_USERNAME');
        $server = $_SERVER;
        $fixture = $_ENV;
        try {
            putenv('TEST_DB_USERNAME');
            unset($_ENV['TEST_DB_USERNAME'], $_SERVER['TEST_DB_USERNAME']);
            $process = new Process([PHP_BINARY, '-d', 'variables_order=GPCS', '-r',
                'exit(getenv("TEST_DB_USERNAME") === "synthetic" && !isset($_ENV["TEST_DB_USERNAME"]) ? 0 : 1);',
            ], null, $environment);
            $process->run();
            self::assertSame(0, $process->getExitCode(), 'child_environment_invalid');
            self::assertTrue($process->getOutput() === '', 'unexpected_child_stdout');
            self::assertTrue($process->getErrorOutput() === '', 'unexpected_child_stderr');
        } finally {
            $_ENV = $fixture;
            $_SERVER = $server;
            $original === false ? putenv('TEST_DB_USERNAME') : putenv('TEST_DB_USERNAME=' . $original);
        }
    }

    public function testCapabilitiesAreVerifiedInActualChildRuntime(): void
    {
        PlatformTestEnvironment::assertRuntimeCapabilities();
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=pcntl_alarm', '-r',
            'require "vendor/autoload.php"; try { tests\\fixtures\\platform\\PlatformTestEnvironment::assertRuntimeCapabilities(); exit(1); } catch (RuntimeException $e) { echo $e->getMessage(); }',
        ], dirname(__DIR__, 5));
        $process->run();
        self::assertSame(0, $process->getExitCode());
        self::assertSame('runtime_capability_unavailable', $process->getOutput());
    }
}
