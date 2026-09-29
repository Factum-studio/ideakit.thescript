<?php

declare(strict_types=1);

use modules\platform\infrastructure\config\CriticalWorkerConfig;

return CriticalWorkerConfig::fromEnvironment($_ENV, require __DIR__ . '/rabbitmq.php');
