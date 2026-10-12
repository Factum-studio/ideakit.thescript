<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\config;

final class TelegramWebhookConfig
{
    /** @throws InvalidTelegramWebhookConfigException */
    public function __construct(public readonly string $botKey, public readonly string $secret)
    {
        if (trim($botKey) !== $botKey || preg_match('/\A\S(?:.{0,62}\S)?\z/us', $botKey) !== 1
            || preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $secret) !== 1) {
            throw new InvalidTelegramWebhookConfigException();
        }
    }

    /** @throws InvalidTelegramWebhookConfigException */
    public static function fromEnvironment(): self
    {
        return new self(self::environment('TELEGRAM_BOT_KEY'), self::environment('TELEGRAM_WEBHOOK_SECRET'));
    }

    private static function environment(string $name): string
    {
        $value = array_key_exists($name, $_ENV) ? $_ENV[$name] : getenv($name);
        if (!is_string($value)) {
            throw new InvalidTelegramWebhookConfigException();
        }

        return $value;
    }
}
