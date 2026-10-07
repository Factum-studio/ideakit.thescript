<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\infrastructure\config\CriticalWorkerConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;

final class CriticalWorkerConfigTest extends Unit
{
    public function testDefaultsAndExplicitLimits(): void
    {
        $defaults = CriticalWorkerConfig::fromEnvironment([], self::broker());
        self::assertSame(1000, $defaults->command->maxMessages);
        self::assertSame(3600, $defaults->command->maxRuntimeSeconds);
        self::assertSame([4, 10, 1, 256, 192, 10], [
            $defaults->settings->handlerTimeoutSeconds, $defaults->settings->brokerOperationTimeoutSeconds,
            $defaults->settings->receiveTimeoutSeconds, $defaults->settings->memoryLimitMib,
            $defaults->settings->softMemoryLimitMib, $defaults->settings->shutdownTimeoutSeconds,
        ]);
        $explicit = CriticalWorkerConfig::fromEnvironment([
            'CRITICAL_WORKER_MAX_MESSAGES' => '10000', 'CRITICAL_WORKER_MAX_RUNTIME_SECONDS' => '1',
            'CRITICAL_WORKER_MEMORY_LIMIT_MIB' => '64', 'CRITICAL_WORKER_SOFT_MEMORY_LIMIT_MIB' => '32',
        ], self::broker());
        self::assertSame(10000, $explicit->command->maxMessages);
        self::assertSame(1, $explicit->command->maxRuntimeSeconds);
        self::assertSame(64, $explicit->settings->memoryLimitMib);
    }

    /**
     * @dataProvider invalidSettings
     * @param array<string, mixed> $environment
     */
    public function testRejectsInvalidOrContradictoryLimits(array $environment, float $poll = 1.0): void
    {
        try {
            CriticalWorkerConfig::fromEnvironment($environment, self::broker($poll));
            self::fail('Expected invalid worker configuration.');
        } catch (CriticalWorkerException $exception) {
            self::assertSame(CriticalWorkerError::CONFIGURATION_INVALID, $exception->error);
            self::assertSame('configuration_invalid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, float}> */
    public static function invalidSettings(): iterable
    {
        foreach (['', ' 1', '1 ', '+1', '-1', '01', '1.5', '1e2', '99999999999999999999', null, 1, true, []] as $index => $value) {
            yield 'strict integer ' . $index => [['CRITICAL_WORKER_MAX_MESSAGES' => $value], 1.0];
        }
        foreach ([
            ['CRITICAL_WORKER_MAX_MESSAGES' => '0'], ['CRITICAL_WORKER_MAX_MESSAGES' => '10001'],
            ['CRITICAL_WORKER_MAX_RUNTIME_SECONDS' => '3601'], ['CRITICAL_WORKER_HANDLER_TIMEOUT_SECONDS' => '5'],
            ['CRITICAL_WORKER_BROKER_OPERATION_TIMEOUT_SECONDS' => '21'], ['CRITICAL_WORKER_RECEIVE_TIMEOUT_SECONDS' => '6'],
            ['CRITICAL_WORKER_MEMORY_LIMIT_MIB' => '63'], ['CRITICAL_WORKER_MEMORY_LIMIT_MIB' => '513'],
            ['CRITICAL_WORKER_SOFT_MEMORY_LIMIT_MIB' => '31'], ['CRITICAL_WORKER_SOFT_MEMORY_LIMIT_MIB' => '256'],
            ['CRITICAL_WORKER_SHUTDOWN_TIMEOUT_SECONDS' => '9'], ['CRITICAL_WORKER_SHUTDOWN_TIMEOUT_SECONDS' => '21'],
        ] as $index => $values) {
            yield 'inconsistent limit ' . $index => [$values, 1.0];
        }
        yield 'poll exceeds heartbeat half' => [[], 5.1];
    }

    private static function broker(float $poll = 1.0): RabbitMqConnectionConfig
    {
        return new RabbitMqConnectionConfig('synthetic', 5672, 'synthetic', 'synthetic-test-only', 'synthetic', consumerPollTimeout: $poll);
    }
}
