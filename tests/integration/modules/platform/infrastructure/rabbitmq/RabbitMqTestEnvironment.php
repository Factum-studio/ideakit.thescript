<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure\rabbitmq;

use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionFactory;
use tests\fixtures\platform\PlatformTestEnvironment;

final class RabbitMqTestEnvironment
{
    public static function factory(): RabbitMqConnectionFactory
    {
        return new RabbitMqConnectionFactory(RabbitMqConnectionConfig::fromEnvironment(PlatformTestEnvironment::brokerEnvironment()));
    }
}
