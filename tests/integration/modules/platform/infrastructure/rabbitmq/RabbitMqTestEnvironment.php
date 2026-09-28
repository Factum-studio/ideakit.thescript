<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure\rabbitmq;

use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionFactory;
use RuntimeException;

final class RabbitMqTestEnvironment
{
    public static function factory(): RabbitMqConnectionFactory
    {
        $environment = [];
        foreach (['HOST', 'PORT', 'USER', 'PASSWORD', 'VHOST'] as $field) {
            $value = $_ENV['TEST_RABBITMQ_' . $field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('Explicit isolated broker configuration is required.');
            }
            $environment['RABBITMQ_' . $field] = $value;
        }
        if (($_ENV['APP_ENV'] ?? null) !== 'test'
            || $environment['RABBITMQ_VHOST'] !== 'ideakit_transport_test'
            || !in_array($environment['RABBITMQ_HOST'], ['rabbitmq-test', '127.0.0.1', 'localhost'], true)
            || ($environment['RABBITMQ_HOST'] === 'rabbitmq-test' && $environment['RABBITMQ_PORT'] !== '5672')
            || ($environment['RABBITMQ_HOST'] !== 'rabbitmq-test' && $environment['RABBITMQ_PORT'] === '5672')
        ) {
            throw new RuntimeException('Only the isolated RabbitMQ test environment is permitted.');
        }

        return new RabbitMqConnectionFactory(RabbitMqConnectionConfig::fromEnvironment($environment));
    }
}
