<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\config;

use Codeception\Test\Unit;
use modules\telegram\infrastructure\config\InvalidTelegramWebhookConfigException;
use modules\telegram\infrastructure\config\TelegramWebhookConfig;

final class TelegramWebhookConfigTest extends Unit
{
    public function testAcceptsBoundariesWithoutNormalizingSecret(): void
    {
        $config = new TelegramWebhookConfig(str_repeat('я', 64), str_repeat('a_-Z09', 42) . 'abcd');
        self::assertSame(str_repeat('я', 64), $config->botKey);
        self::assertSame(256, strlen($config->secret));
        self::assertSame('x', (new TelegramWebhookConfig('b', 'x'))->secret);
    }

    /** @dataProvider invalidConfig */
    public function testRejectsInvalidConfigurationSafely(string $key, string $secret): void
    {
        $this->expectException(InvalidTelegramWebhookConfigException::class);
        $this->expectExceptionMessage('invalid_telegram_webhook_config');
        new TelegramWebhookConfig($key, $secret);
    }

    public static function invalidConfig(): iterable
    {
        foreach (['', ' bot', "bot\n", str_repeat('я', 65), "\xFF"] as $key) {
            yield [$key, 'test-secret'];
        }
        foreach (['', ' secret', 'secret ', "secret\n", 'a,b', 'секрет', str_repeat('a', 257)] as $secret) {
            yield ['test-bot', $secret];
        }
    }

    public function testEnvironmentPrecedenceAndInvalidValuesDoNotFallBack(): void
    {
        $names = ['TELEGRAM_BOT_KEY', 'TELEGRAM_WEBHOOK_SECRET'];
        $saved = $_ENV;
        $process = array_map('getenv', $names);
        try {
            putenv('TELEGRAM_BOT_KEY=process-bot');
            putenv('TELEGRAM_WEBHOOK_SECRET=process-secret');
            unset($_ENV['TELEGRAM_BOT_KEY'], $_ENV['TELEGRAM_WEBHOOK_SECRET']);
            self::assertSame('process-bot', TelegramWebhookConfig::fromEnvironment()->botKey);
            $_ENV['TELEGRAM_BOT_KEY'] = 'environment-bot';
            $_ENV['TELEGRAM_WEBHOOK_SECRET'] = 'environment-secret';
            $config = TelegramWebhookConfig::fromEnvironment();
            self::assertSame('environment-bot', $config->botKey);
            self::assertSame('environment-secret', $config->secret);
            $_ENV['TELEGRAM_WEBHOOK_SECRET'] = false;
            $this->expectException(InvalidTelegramWebhookConfigException::class);
            TelegramWebhookConfig::fromEnvironment();
        } finally {
            $_ENV = $saved;
            foreach ($names as $index => $name) {
                putenv($process[$index] === false ? $name : $name . '=' . $process[$index]);
            }
        }
    }

    public function testMissingEnvironmentIsNotAnEmptySecret(): void
    {
        $saved = $_ENV;
        $secret = getenv('TELEGRAM_WEBHOOK_SECRET');
        try {
            $_ENV['TELEGRAM_BOT_KEY'] = 'test-bot';
            unset($_ENV['TELEGRAM_WEBHOOK_SECRET']);
            putenv('TELEGRAM_WEBHOOK_SECRET');
            $this->expectException(InvalidTelegramWebhookConfigException::class);
            TelegramWebhookConfig::fromEnvironment();
        } finally {
            $_ENV = $saved;
            putenv($secret === false ? 'TELEGRAM_WEBHOOK_SECRET' : 'TELEGRAM_WEBHOOK_SECRET=' . $secret);
        }
    }
}
