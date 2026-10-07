<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\http;

use RuntimeException;
use Throwable;

final class TelegramWebhookRequestException extends RuntimeException
{
    public function __construct(public readonly TelegramWebhookRejectionReason $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason->value, 0, $previous);
    }
}
