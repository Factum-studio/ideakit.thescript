<?php

declare(strict_types=1);

namespace modules\telegram\application\exception;

use RuntimeException;
use Throwable;

final class TelegramUpdateAcceptanceIntegrityException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('telegram_update_acceptance_integrity_failure', 0, $previous);
    }
}
