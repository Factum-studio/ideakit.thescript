<?php

declare(strict_types=1);

namespace modules\platform\application\exception;

use modules\platform\application\enum\OutboxRelayError;
use RuntimeException;

final class OutboxRelayException extends RuntimeException
{
    public function __construct(public readonly OutboxRelayError $error)
    {
        parent::__construct($error->value);
    }
}
