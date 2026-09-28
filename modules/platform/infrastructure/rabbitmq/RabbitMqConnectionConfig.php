<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;

final class RabbitMqConnectionConfig
{
    /** @throws BrokerTransportException */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $password,
        public readonly string $vhost,
        public readonly float $connectionTimeout = 3.0,
        public readonly float $channelRpcTimeout = 5.0,
        public readonly int $heartbeat = 10,
        public readonly float $readTimeout = 25.0,
        public readonly float $writeTimeout = 25.0,
        public readonly float $confirmTimeout = 5.0,
        public readonly float $consumerPollTimeout = 1.0,
    ) {
        foreach ([$host, $user, $password, $vhost] as $value) {
            self::text($value);
        }
        if ($port < 1 || $port > 65535 || $heartbeat < 1 || $heartbeat > 60) {
            throw new BrokerTransportException(BrokerTransportErrorCode::CONFIGURATION_INVALID);
        }
        foreach ([$connectionTimeout, $channelRpcTimeout, $confirmTimeout, $consumerPollTimeout] as $value) {
            self::timeout($value, 30.0);
        }
        foreach ([$readTimeout, $writeTimeout] as $value) {
            self::timeout($value, 120.0);
            if ($value <= 2 * $heartbeat || $channelRpcTimeout > $value) {
                throw new BrokerTransportException(BrokerTransportErrorCode::CONFIGURATION_INVALID);
            }
        }
    }

    /**
     * @param array<string, mixed> $environment
     * @throws BrokerTransportException
     */
    public static function fromEnvironment(array $environment): self
    {
        return new self(
            self::text($environment['RABBITMQ_HOST'] ?? null),
            self::integer($environment['RABBITMQ_PORT'] ?? null),
            self::text($environment['RABBITMQ_USER'] ?? null),
            self::text($environment['RABBITMQ_PASSWORD'] ?? null),
            self::text($environment['RABBITMQ_VHOST'] ?? null),
            self::seconds($environment['RABBITMQ_CONNECTION_TIMEOUT'] ?? '3'),
            self::seconds($environment['RABBITMQ_CHANNEL_RPC_TIMEOUT'] ?? '5'),
            self::integer($environment['RABBITMQ_HEARTBEAT'] ?? '10'),
            self::seconds($environment['RABBITMQ_READ_TIMEOUT'] ?? '25'),
            self::seconds($environment['RABBITMQ_WRITE_TIMEOUT'] ?? '25'),
            self::seconds($environment['RABBITMQ_CONFIRM_TIMEOUT'] ?? '5'),
            self::seconds($environment['RABBITMQ_CONSUMER_POLL_TIMEOUT'] ?? '1'),
        );
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $value) !== 0
        ) {
            throw new BrokerTransportException(BrokerTransportErrorCode::CONFIGURATION_INVALID);
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,4}\z/', $value) !== 1) {
            throw new BrokerTransportException(BrokerTransportErrorCode::CONFIGURATION_INVALID);
        }

        return (int) $value;
    }

    private static function seconds(mixed $value): float
    {
        if (!is_string($value) || preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/', $value) !== 1) {
            throw new BrokerTransportException(BrokerTransportErrorCode::CONFIGURATION_INVALID);
        }

        return (float) $value;
    }

    private static function timeout(float $value, float $maximum): void
    {
        if (!is_finite($value) || $value <= 0 || $value > $maximum) {
            throw new BrokerTransportException(BrokerTransportErrorCode::CONFIGURATION_INVALID);
        }
    }
}
