<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\transport\update;

use Codeception\Test\Unit;
use modules\telegram\infrastructure\transport\update\MessageUpdate;
use modules\telegram\infrastructure\transport\update\TelegramMessageIntent;
use modules\telegram\infrastructure\transport\update\TelegramMessageIntentClassifier;
use modules\telegram\infrastructure\transport\update\TelegramSenderSnapshot;

final class TelegramMessageIntentClassifierTest extends Unit
{
    /**
     * @dataProvider messageTexts
     */
    public function testClassifiesOnlyExactSupportedText(string $text, TelegramMessageIntent $expected): void
    {
        $update = new MessageUpdate(
            17,
            '202',
            new TelegramSenderSnapshot('101', 'Test', null, null, null),
            33,
            $text,
        );

        self::assertSame($expected, (new TelegramMessageIntentClassifier())->classify($update));
        self::assertSame($text, $update->text);
    }

    public static function messageTexts(): array
    {
        return [
            'start' => ['/start', TelegramMessageIntent::START],
            'next' => ['Следующая идея', TelegramMessageIntent::NEXT_IDEA],
            'cancel' => ['Отменить', TelegramMessageIntent::CANCEL],
            'start with payload' => ['/start payload', TelegramMessageIntent::OTHER_TEXT],
            'start addressed to bot' => ['/start@bot', TelegramMessageIntent::OTHER_TEXT],
            'start with space' => [' /start', TelegramMessageIntent::OTHER_TEXT],
            'next with space' => ['Следующая идея ', TelegramMessageIntent::OTHER_TEXT],
            'next with different case' => ['следующая идея', TelegramMessageIntent::OTHER_TEXT],
            'cancel with space' => ['Отменить ', TelegramMessageIntent::OTHER_TEXT],
            'other command' => ['/help', TelegramMessageIntent::OTHER_TEXT],
            'comment-like text' => ['Хочу обсудить разработку', TelegramMessageIntent::OTHER_TEXT],
        ];
    }
}
