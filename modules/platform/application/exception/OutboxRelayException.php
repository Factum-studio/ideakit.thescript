<?php

declare(strict_types=1);

namespace modules\platform\application\exception;

use modules\platform\application\enum\OutboxRelayError;
use RuntimeException;
use Throwable;

final class OutboxRelayException extends RuntimeException
{
    public function __construct(public readonly OutboxRelayError $error, ?Throwable $previous = null)
    {
        parent::__construct($error->value, 0, $previous);
    }
}
