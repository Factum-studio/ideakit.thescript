<?php

declare(strict_types=1);

namespace modules\platform\application\exception;

use modules\platform\application\enum\CriticalWorkerError;
use RuntimeException;
use Throwable;

final class CriticalWorkerException extends RuntimeException
{
    public function __construct(public readonly CriticalWorkerError $error, ?Throwable $previous = null)
    {
        parent::__construct($error->value, 0, $previous);
    }
}
