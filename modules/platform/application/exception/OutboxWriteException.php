<?php

declare(strict_types=1);

namespace modules\platform\application\exception;

use modules\platform\application\enum\OutboxWriteFailure;
use RuntimeException;
use Throwable;

final class OutboxWriteException extends RuntimeException
{
    public function __construct(
        public readonly OutboxWriteFailure $failure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($failure->value, 0, $previous);
    }
}
