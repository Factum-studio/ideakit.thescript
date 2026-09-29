<?php

declare(strict_types=1);

use modules\platform\infrastructure\config\OutboxRelayConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;

return OutboxRelayConfig::fromEnvironment($_ENV, Yii::$container->get(RabbitMqConnectionConfig::class));
