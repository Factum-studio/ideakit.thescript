<?php

declare(strict_types=1);

namespace modules\platform\application\exception;

use modules\platform\application\enum\OutboxMaintenanceError;
use RuntimeException;
use Throwable;

final class OutboxMaintenanceException extends RuntimeException
{
    public function __construct(public readonly OutboxMaintenanceError $error, ?Throwable $previous = null)
    {
        parent::__construct($error->value, 0, $previous);
    }
}
