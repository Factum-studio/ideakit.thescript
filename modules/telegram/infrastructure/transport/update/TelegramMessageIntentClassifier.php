<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

final class TelegramMessageIntentClassifier
{
    public function classify(MessageUpdate $update): TelegramMessageIntent
    {
        return match ($update->text) {
            '/start' => TelegramMessageIntent::START,
            'Следующая идея' => TelegramMessageIntent::NEXT_IDEA,
            'Отменить' => TelegramMessageIntent::CANCEL,
            default => TelegramMessageIntent::OTHER_TEXT,
        };
    }
}
