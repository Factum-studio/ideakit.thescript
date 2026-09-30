<?php

declare(strict_types=1);

namespace modules\platform\application\exception;

use modules\platform\application\enum\OutboxMaintenanceError;
use RuntimeException;

final class OutboxMaintenanceException extends RuntimeException
{
    public function __construct(public readonly OutboxMaintenanceError $error)
    {
        parent::__construct($error->value);
    }
}
