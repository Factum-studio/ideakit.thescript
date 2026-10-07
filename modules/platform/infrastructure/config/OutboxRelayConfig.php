<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\config;

use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;

final class OutboxRelayConfig
{
    private function __construct(
        public readonly OutboxRelaySettings $settings,
        public readonly int $defaultLimit,
    ) {
    }

    /** @param array<string, mixed> $environment */
    public static function fromEnvironment(array $environment, RabbitMqConnectionConfig $broker): self
    {
        $settings = new OutboxRelaySettings(
            self::integer($environment, 'OUTBOX_RELAY_MAX_ATTEMPTS', '5'),
            self::integer($environment, 'OUTBOX_RELAY_LEASE_SECONDS', '600'),
            self::integer($environment, 'OUTBOX_RELAY_RETRY_BASE_SECONDS', '15'),
            self::integer($environment, 'OUTBOX_RELAY_RETRY_MAX_SECONDS', '900'),
        );
        $limit = self::integer($environment, 'OUTBOX_RELAY_LIMIT', '10');
        if ($limit > 100 || $settings->leaseSeconds < $broker->publicationTimeoutSeconds() + 30) {
            throw new OutboxRelayException(OutboxRelayError::CONFIGURATION_INVALID);
        }

        return new self($settings, $limit);
    }

    /** @param array<string, mixed> $environment */
    private static function integer(array $environment, string $name, string $default): int
    {
        $value = array_key_exists($name, $environment) ? $environment[$name] : $default;
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,3}\z/', $value) !== 1) {
            throw new OutboxRelayException(OutboxRelayError::CONFIGURATION_INVALID);
        }

        return (int) $value;
    }
}
