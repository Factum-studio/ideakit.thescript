<?php

declare(strict_types=1);

namespace modules\telegram\application\exception;

use RuntimeException;
use Throwable;

final class TelegramUpdateAcceptanceUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('telegram_update_acceptance_unavailable', 0, $previous);
    }
}
