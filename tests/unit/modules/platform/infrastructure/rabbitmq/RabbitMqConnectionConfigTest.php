<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure\rabbitmq;

use Codeception\Test\Unit;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionFactory;

final class RabbitMqConnectionConfigTest extends Unit
{
    public function testValidatesEnvironmentWithoutOpeningConnection(): void
    {
        $config = RabbitMqConnectionConfig::fromEnvironment(self::environment());
        $factory = new RabbitMqConnectionFactory($config);

        self::assertInstanceOf(RabbitMqConnectionFactory::class, $factory);
        self::assertSame(5672, $config->port);
        self::assertSame(3.0, $config->connectionTimeout);
        self::assertSame(5.0, $config->channelRpcTimeout);
        self::assertSame(10, $config->heartbeat);
        self::assertSame(25.0, $config->readTimeout);
        self::assertSame(25.0, $config->writeTimeout);
        self::assertSame(5.0, $config->confirmTimeout);
        self::assertSame(1.0, $config->consumerPollTimeout);
    }

    /**
     * @dataProvider invalidEnvironment
     * @param array<string, mixed> $changes
     */
    public function testRejectsUnsafeConfigurationWithoutLeakingValues(array $changes): void
    {
        try {
            RabbitMqConnectionConfig::fromEnvironment(array_replace(self::environment(), $changes));
            self::fail('Expected invalid broker configuration.');
        } catch (BrokerTransportException $exception) {
            self::assertSame(BrokerTransportErrorCode::CONFIGURATION_INVALID, $exception->errorCode);
            self::assertSame('configuration_invalid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEnvironment(): iterable
    {
        foreach (['HOST', 'USER', 'PASSWORD', 'VHOST'] as $field) {
            foreach ([null, '', '   ', 42, "bad\nvalue", str_repeat('x', 256)] as $index => $value) {
                yield $field . '-' . $index => [['RABBITMQ_' . $field => $value]];
            }
        }
        foreach ([null, true, '0', '-1', '65536', '1.5', '5672junk', ' 5672'] as $index => $value) {
            yield 'port-' . $index => [['RABBITMQ_PORT' => $value]];
        }
        foreach (['CONNECTION_TIMEOUT', 'CHANNEL_RPC_TIMEOUT', 'CONFIRM_TIMEOUT', 'CONSUMER_POLL_TIMEOUT'] as $field) {
            foreach (['0', '-1', '30.1', 'NaN', '1second', true] as $index => $value) {
                yield $field . '-' . $index => [['RABBITMQ_' . $field => $value]];
            }
        }
        foreach (['READ_TIMEOUT', 'WRITE_TIMEOUT'] as $field) {
            foreach (['0', '-1', '120.1', '20', 'NaN'] as $index => $value) {
                yield $field . '-' . $index => [['RABBITMQ_' . $field => $value]];
            }
        }
        foreach (['0', '-1', '61', '1.5', false] as $index => $value) {
            yield 'heartbeat-' . $index => [['RABBITMQ_HEARTBEAT' => $value]];
        }
        yield 'rpc exceeds IO' => [['RABBITMQ_CHANNEL_RPC_TIMEOUT' => '30']];
    }

    public function testAcceptsExplicitBoundaryValues(): void
    {
        $config = RabbitMqConnectionConfig::fromEnvironment(array_replace(self::environment(), [
            'RABBITMQ_USER' => str_repeat('x', 255),
            'RABBITMQ_PORT' => '65535',
            'RABBITMQ_HEARTBEAT' => '1',
            'RABBITMQ_READ_TIMEOUT' => '120',
            'RABBITMQ_WRITE_TIMEOUT' => '120',
            'RABBITMQ_CONNECTION_TIMEOUT' => '0.1',
            'RABBITMQ_CHANNEL_RPC_TIMEOUT' => '30',
            'RABBITMQ_CONFIRM_TIMEOUT' => '30',
            'RABBITMQ_CONSUMER_POLL_TIMEOUT' => '30',
        ]));

        self::assertSame(65535, $config->port);
        self::assertSame(0.1, $config->connectionTimeout);
        self::assertSame(120.0, $config->writeTimeout);
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        return [
            'RABBITMQ_HOST' => '127.0.0.1',
            'RABBITMQ_PORT' => '5672',
            'RABBITMQ_USER' => 'synthetic-user',
            'RABBITMQ_PASSWORD' => 'synthetic-password',
            'RABBITMQ_VHOST' => 'synthetic-vhost',
        ];
    }
}
