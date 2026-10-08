<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\transport\http;

use Codeception\Test\Unit;
use modules\telegram\application\enum\IncomingTelegramUpdateType;
use modules\telegram\application\enum\TelegramUpdateIgnoreReason;
use modules\telegram\infrastructure\transport\http\TelegramWebhookUpdateMapper;
use modules\telegram\infrastructure\transport\update\InvalidTelegramUpdateException;
use modules\telegram\infrastructure\transport\update\TelegramUpdateParser;

final class TelegramWebhookUpdateMapperTest extends Unit
{
    /** @dataProvider updates */
    public function testMapsParserMetadataAndHashesOriginalBytes(
        string $body,
        string $type,
        ?string $reason,
    ): void {
        $raw = " \n" . $body . "\n ";
        $command = (new TelegramWebhookUpdateMapper(new TelegramUpdateParser()))->fromRawPayload('test-bot', $raw);

        self::assertSame('test-bot', $command->botKey);
        self::assertSame(17, $command->telegramUpdateId);
        self::assertSame(IncomingTelegramUpdateType::from($type), $command->updateType);
        self::assertSame($reason === null ? '202' : null, $command->chatId);
        self::assertSame($reason === null ? null : TelegramUpdateIgnoreReason::from($reason), $command->ignoredReason);
        self::assertSame($raw, $command->rawPayload);
        self::assertSame(hash('sha256', $raw), $command->payloadHash);
        self::assertNotSame(hash('sha256', $body), $command->payloadHash);
        self::assertSame('telegram.update/1.0', $command->payloadSchemaVersion);
    }

    public static function updates(): iterable
    {
        yield 'message' => [
            '{"update_id":17,"message":{"message_id":3,"chat":{"id":202,"type":"private"},'
            . '"from":{"id":101,"is_bot":false,"first_name":"Test"},"text":"test"}}',
            'MESSAGE', null,
        ];
        yield 'callback' => [
            '{"update_id":17,"callback_query":{"id":"test-query","data":"unverified",'
            . '"from":{"id":101,"is_bot":false,"first_name":"Test"},'
            . '"message":{"message_id":3,"chat":{"id":202,"type":"private"}}}}',
            'CALLBACK_QUERY', null,
        ];
        yield 'membership' => [
            '{"update_id":17,"my_chat_member":{"chat":{"id":202,"type":"private"},'
            . '"from":{"id":101,"is_bot":false,"first_name":"Test"},"date":1,'
            . '"old_chat_member":{"user":{"id":303},"status":"member"},'
            . '"new_chat_member":{"user":{"id":303},"status":"kicked"}}}',
            'MY_CHAT_MEMBER', null,
        ];
        yield 'group ignores chat id' => [
            '{"update_id":17,"message":{"chat":{"id":-202,"type":"group"}}}',
            'MESSAGE', 'NON_PRIVATE_CHAT',
        ];
        yield 'unknown update' => ['{"update_id":17}', 'UNSUPPORTED', 'UNSUPPORTED_UPDATE_TYPE'];
        yield 'structure' => ['{"update_id":17,"my_chat_member":null}', 'MY_CHAT_MEMBER', 'INVALID_STRUCTURE'];
        yield 'inline callback' => [
            '{"update_id":17,"callback_query":{"id":"test-query","data":"unverified"}}',
            'CALLBACK_QUERY', 'UNSUPPORTED_CALLBACK_CONTEXT',
        ];
        yield 'content' => [
            '{"update_id":17,"message":{"message_id":3,"chat":{"id":202,"type":"private"},'
            . '"from":{"id":101,"is_bot":false,"first_name":"Test"}}}',
            'MESSAGE', 'UNSUPPORTED_CONTENT',
        ];
        yield 'actor' => [
            '{"update_id":17,"message":{"chat":{"id":202,"type":"private"},"from":{"is_bot":true}}}',
            'MESSAGE', 'UNSUPPORTED_ACTOR',
        ];
    }

    public function testParserFailureDoesNotProduceACommand(): void
    {
        $this->expectException(InvalidTelegramUpdateException::class);
        (new TelegramWebhookUpdateMapper(new TelegramUpdateParser()))->fromRawPayload('test-bot', '{');
    }
}
