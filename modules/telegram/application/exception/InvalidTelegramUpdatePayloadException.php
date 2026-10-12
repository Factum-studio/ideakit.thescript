<?php

declare(strict_types=1);

namespace modules\telegram\application\exception;

use RuntimeException;
use Throwable;

final class InvalidTelegramUpdatePayloadException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('invalid_telegram_update_payload', 0, $previous);
    }
}
