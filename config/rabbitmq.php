<?php

declare(strict_types=1);

use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;

return RabbitMqConnectionConfig::fromEnvironment($_ENV);
