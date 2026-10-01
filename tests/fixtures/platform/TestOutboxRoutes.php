<?php

declare(strict_types=1);

namespace tests\fixtures\platform;

use modules\platform\application\route\OutboxRoute;
use modules\platform\application\route\OutboxRouteRegistry;
use modules\telegram\infrastructure\messaging\TelegramUpdateReceivedPayloadCodec;

final class TestOutboxRoutes
{
    public static function registry(): OutboxRouteRegistry
    {
        return new OutboxRouteRegistry([self::telegram()]);
    }

    public static function telegram(): OutboxRoute
    {
        return new OutboxRoute(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            'RABBITMQ',
            'critical',
            1024,
            new TelegramUpdateReceivedPayloadCodec(),
        );
    }
}
