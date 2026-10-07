<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\config;

use RuntimeException;

final class InvalidTelegramWebhookConfigException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('invalid_telegram_webhook_config');
    }
}
