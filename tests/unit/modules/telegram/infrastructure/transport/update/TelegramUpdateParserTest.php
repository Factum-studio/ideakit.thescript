<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\transport\update;

use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\telegram\infrastructure\transport\update\CallbackQueryUpdate;
use modules\telegram\infrastructure\transport\update\InvalidTelegramUpdateException;
use modules\telegram\infrastructure\transport\update\IgnoredUpdate;
use modules\telegram\infrastructure\transport\update\IgnoredUpdateReason;
use modules\telegram\infrastructure\transport\update\MessageUpdate;
use modules\telegram\infrastructure\transport\update\MyChatMemberUpdate;
use modules\telegram\infrastructure\transport\update\TelegramChatMemberStatus;
use modules\telegram\infrastructure\transport\update\TelegramUpdate;
use modules\telegram\infrastructure\transport\update\TelegramUpdateParser;
use modules\telegram\infrastructure\transport\update\TelegramUpdateType;

final class TelegramUpdateParserTest extends Unit
{
    /**
     * @dataProvider invalidEnvelope
     */
    public function testRejectsUntrustworthyEnvelopeWithoutLeakingInput(string $json, string $code): void
    {
        try {
            (new TelegramUpdateParser())->parse($json);
            self::fail('Expected invalid Telegram update');
        } catch (InvalidTelegramUpdateException $exception) {
            self::assertSame($code, $exception->getMessage());
            self::assertStringNotContainsString('private-input', $exception->getMessage());
        }
    }

    public static function invalidEnvelope(): array
    {
        return [
            'oversized' => ['{"update_id":1,"private":"' . str_repeat('x', 65_537) . '"}', 'payload_too_large'],
            'malformed' => ['{"update_id":1,"private":"private-input"', 'invalid_json'],
            'too deep' => ['{"update_id":1,"nested":' . str_repeat('[', 33) . '0' . str_repeat(']', 33) . '}', 'invalid_json'],
            'scalar root' => ['42', 'invalid_root'],
            'array root' => ['[]', 'invalid_root'],
            'missing id' => ['{}', 'invalid_update_id'],
            'string id' => ['{"update_id":"42"}', 'invalid_update_id'],
            'float id' => ['{"update_id":42.0}', 'invalid_update_id'],
            'boolean id' => ['{"update_id":true}', 'invalid_update_id'],
            'null id' => ['{"update_id":null}', 'invalid_update_id'],
            'negative id' => ['{"update_id":-1}', 'invalid_update_id'],
            'overflowed id' => ['{"update_id":9223372036854775808}', 'invalid_update_id'],
        ];
    }

    public function testUnknownUpdateTypeIsIgnored(): void
    {
        $update = $this->parse(['update_id' => 7, 'poll' => ['id' => 'opaque']]);

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(7, $update->updateId());
        self::assertSame(TelegramUpdateType::UNSUPPORTED, $update->type());
        self::assertSame(IgnoredUpdateReason::UNSUPPORTED_UPDATE_TYPE, $update->reason);
    }

    public function testPayloadAtExactByteLimitIsAccepted(): void
    {
        $prefix = '{"update_id":7,"poll":"';
        $suffix = '"}';
        $json = $prefix . str_repeat('x', TelegramUpdateParser::MAX_PAYLOAD_BYTES - strlen($prefix . $suffix)) . $suffix;

        self::assertSame(TelegramUpdateParser::MAX_PAYLOAD_BYTES, strlen($json));
        self::assertInstanceOf(IgnoredUpdate::class, (new TelegramUpdateParser())->parse($json));
    }

    public function testMultipleKnownTypesAreNotChosenArbitrarily(): void
    {
        $update = $this->parse([
            'update_id' => 7,
            'message' => ['chat' => ['type' => 'private']],
            'callback_query' => ['message' => ['chat' => ['type' => 'private']]],
        ]);

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(TelegramUpdateType::UNSUPPORTED, $update->type());
        self::assertSame(IgnoredUpdateReason::INVALID_STRUCTURE, $update->reason);
    }

    /**
     * @dataProvider nonPrivateUpdates
     */
    public function testNonPrivateChatIsIgnoredBeforeReadingActor(array $payload, TelegramUpdateType $type): void
    {
        $update = $this->parse($payload);

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame($type, $update->type());
        self::assertSame(IgnoredUpdateReason::NON_PRIVATE_CHAT, $update->reason);
        self::assertSame(
            $type === TelegramUpdateType::CALLBACK_QUERY ? 'query-7' : null,
            $update->callbackQueryId,
        );
    }

    public static function nonPrivateUpdates(): array
    {
        $cases = [];
        foreach (['group', 'supergroup', 'channel'] as $chatType) {
            $cases['message ' . $chatType] = [
                ['update_id' => 7, 'message' => ['chat' => ['type' => $chatType]]],
                TelegramUpdateType::MESSAGE,
            ];
            $cases['callback ' . $chatType] = [
                [
                    'update_id' => 7,
                    'callback_query' => [
                        'id' => 'query-7',
                        'message' => ['chat' => ['type' => $chatType]],
                    ],
                ],
                TelegramUpdateType::CALLBACK_QUERY,
            ];
            $cases['member ' . $chatType] = [
                ['update_id' => 7, 'my_chat_member' => ['chat' => ['type' => $chatType]]],
                TelegramUpdateType::MY_CHAT_MEMBER,
            ];
        }

        return $cases;
    }

    public function testPrivateMessagePreservesDistinctIdsTextAndOptionalSnapshot(): void
    {
        $payload = self::privateMessage();
        $payload['message']['text'] = "Привет\n";
        $payload['message']['from']['username'] = 'tester';
        $payload['message']['from']['last_name'] = 'Example';
        $payload['message']['from']['language_code'] = 'ru';
        $payload['message']['future_field'] = ['unknown' => true];

        $update = $this->parse($payload);

        self::assertInstanceOf(MessageUpdate::class, $update);
        self::assertSame(7, $update->updateId());
        self::assertSame('202', $update->chatId);
        self::assertSame('101', $update->sender->userId);
        self::assertSame('Test', $update->sender->firstName);
        self::assertSame('tester', $update->sender->username);
        self::assertSame('Example', $update->sender->lastName);
        self::assertSame('ru', $update->sender->languageCode);
        self::assertSame(33, $update->messageId);
        self::assertSame("Привет\n", $update->text);
    }

    public function testMessageWithoutTextIsUnsupportedContent(): void
    {
        $payload = self::privateMessage();
        unset($payload['message']['text']);

        $update = $this->parse($payload);

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(IgnoredUpdateReason::UNSUPPORTED_CONTENT, $update->reason);
    }

    /**
     * @dataProvider invalidMessageFields
     */
    public function testInvalidPrivateMessageFieldsAreIgnored(array $changes, IgnoredUpdateReason $reason): void
    {
        $update = $this->parse(array_replace_recursive(self::privateMessage(), $changes));

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(TelegramUpdateType::MESSAGE, $update->type());
        self::assertSame($reason, $update->reason);
    }

    public static function invalidMessageFields(): array
    {
        return [
            'text type' => [['message' => ['text' => 3]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'empty text' => [['message' => ['text' => '']], IgnoredUpdateReason::INVALID_STRUCTURE],
            'text too long' => [
                ['message' => ['text' => str_repeat('x', 4097)]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
            ],
            'chat id string' => [['message' => ['chat' => ['id' => '202']]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'zero chat id' => [['message' => ['chat' => ['id' => 0]]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'message id float' => [['message' => ['message_id' => 33.0]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'message id zero' => [['message' => ['message_id' => 0]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'sender id string' => [['message' => ['from' => ['id' => '101']]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'sender id zero' => [['message' => ['from' => ['id' => 0]]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'sender id overflow' => [
                ['message' => ['from' => ['id' => '9223372036854775808']]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
            ],
            'sender name blank' => [['message' => ['from' => ['first_name' => '']]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'sender name too long' => [
                ['message' => ['from' => ['first_name' => str_repeat('x', 256)]]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
            ],
            'optional null' => [['message' => ['from' => ['username' => null]]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'optional too long' => [
                ['message' => ['from' => ['username' => str_repeat('x', 65)]]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
            ],
            'invalid is bot' => [['message' => ['from' => ['is_bot' => 0]]], IgnoredUpdateReason::INVALID_STRUCTURE],
            'bot actor' => [['message' => ['from' => ['is_bot' => true]]], IgnoredUpdateReason::UNSUPPORTED_ACTOR],
            'unknown chat type' => [['message' => ['chat' => ['type' => 'other']]], IgnoredUpdateReason::INVALID_STRUCTURE],
        ];
    }

    public function testMissingSenderIsInvalidStructure(): void
    {
        $payload = self::privateMessage();
        unset($payload['message']['from']);

        $update = $this->parse($payload);

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(IgnoredUpdateReason::INVALID_STRUCTURE, $update->reason);
    }

    public function testChatListIsInvalidStructure(): void
    {
        $payload = self::privateMessage();
        $payload['message']['chat'] = [];

        $update = $this->parse($payload);

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(IgnoredUpdateReason::INVALID_STRUCTURE, $update->reason);
    }

    public function testMessageAcceptsBoundaryLengths(): void
    {
        $payload = self::privateMessage();
        $payload['message']['text'] = str_repeat('а', 4096);
        $payload['message']['from']['first_name'] = str_repeat('я', 255);

        self::assertInstanceOf(MessageUpdate::class, $this->parse($payload));
    }

    public function testNegativeChatIdIsPreservedAsSignedDecimalString(): void
    {
        $payload = self::privateMessage();
        $payload['message']['chat']['id'] = -100;

        $update = $this->parse($payload);

        self::assertInstanceOf(MessageUpdate::class, $update);
        self::assertSame('-100', $update->chatId);
    }

    public function testPrivateCallbackRetainsOpaqueDataAndDistinctIds(): void
    {
        $payload = self::privateCallback();
        $payload['callback_query']['message']['date'] = 0;
        $payload['callback_query']['data'] = str_repeat('x', 64);

        $update = $this->parse($payload);

        self::assertInstanceOf(CallbackQueryUpdate::class, $update);
        self::assertSame('202', $update->chatId);
        self::assertSame('101', $update->sender->userId);
        self::assertSame('query-7', $update->callbackQueryId);
        self::assertSame(33, $update->messageId);
        self::assertSame(str_repeat('x', 64), $update->unverifiedData);
    }

    /**
     * @dataProvider unsupportedCallbackContexts
     */
    public function testUnsupportedCallbackContextRetainsOnlyValidQueryId(array $payload): void
    {
        $update = $this->parse($payload);

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(TelegramUpdateType::CALLBACK_QUERY, $update->type());
        self::assertSame(IgnoredUpdateReason::UNSUPPORTED_CALLBACK_CONTEXT, $update->reason);
        self::assertSame('query-7', $update->callbackQueryId);
    }

    public static function unsupportedCallbackContexts(): array
    {
        $inline = self::privateCallback();
        unset($inline['callback_query']['message']);
        $inline['callback_query']['inline_message_id'] = 'inline';

        $game = self::privateCallback();
        unset($game['callback_query']['data']);
        $game['callback_query']['game_short_name'] = 'game';

        $missingData = self::privateCallback();
        unset($missingData['callback_query']['data']);

        $unusable = self::privateCallback();
        $unusable['callback_query']['message'] = [];

        return [
            'inline only' => [$inline],
            'game callback' => [$game],
            'missing data' => [$missingData],
            'unusable message' => [$unusable],
        ];
    }

    /**
     * @dataProvider invalidCallbackFields
     */
    public function testInvalidCallbackFieldsAreIgnored(array $changes, IgnoredUpdateReason $reason, ?string $queryId): void
    {
        $update = $this->parse(array_replace_recursive(self::privateCallback(), $changes));

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(TelegramUpdateType::CALLBACK_QUERY, $update->type());
        self::assertSame($reason, $update->reason);
        self::assertSame($queryId, $update->callbackQueryId);
    }

    public static function invalidCallbackFields(): array
    {
        return [
            'empty query id' => [
                ['callback_query' => ['id' => '']],
                IgnoredUpdateReason::INVALID_STRUCTURE,
                null,
            ],
            'query id too long' => [
                ['callback_query' => ['id' => str_repeat('x', 257)]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
                null,
            ],
            'data wrong type' => [
                ['callback_query' => ['data' => 4]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
                'query-7',
            ],
            'data empty' => [
                ['callback_query' => ['data' => '']],
                IgnoredUpdateReason::INVALID_STRUCTURE,
                'query-7',
            ],
            'data too long' => [
                ['callback_query' => ['data' => str_repeat('x', 65)]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
                'query-7',
            ],
            'message id invalid' => [
                ['callback_query' => ['message' => ['message_id' => 0]]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
                'query-7',
            ],
            'sender invalid' => [
                ['callback_query' => ['from' => ['id' => '101']]],
                IgnoredUpdateReason::INVALID_STRUCTURE,
                'query-7',
            ],
            'bot actor' => [
                ['callback_query' => ['from' => ['is_bot' => true]]],
                IgnoredUpdateReason::UNSUPPORTED_ACTOR,
                'query-7',
            ],
        ];
    }

    private static function privateCallback(): array
    {
        return [
            'update_id' => 7,
            'callback_query' => [
                'id' => 'query-7',
                'from' => ['id' => 101, 'is_bot' => false, 'first_name' => 'Test'],
                'message' => [
                    'message_id' => 33,
                    'chat' => ['id' => 202, 'type' => 'private'],
                ],
                'data' => 'opaque',
            ],
        ];
    }

    public function testPrivateChatMemberPreservesDistinctActorAndChangedMemberInUtc(): void
    {
        $payload = self::privateChatMember();
        $payload['my_chat_member']['from']['is_bot'] = true;

        $update = $this->parse($payload);

        self::assertInstanceOf(MyChatMemberUpdate::class, $update);
        self::assertSame('202', $update->chatId);
        self::assertSame('101', $update->actorUserId);
        self::assertSame('303', $update->changedMemberUserId);
        self::assertSame(TelegramChatMemberStatus::MEMBER, $update->oldStatus);
        self::assertSame(TelegramChatMemberStatus::KICKED, $update->newStatus);
        self::assertSame('UTC', $update->occurredAt->getTimezone()->getName());
        self::assertSame('2026-09-25T10:00:00+00:00', $update->occurredAt->format(DateTimeImmutable::ATOM));
    }

    /**
     * @dataProvider invalidChatMemberFields
     */
    public function testInvalidChatMemberFieldsAreIgnored(array $changes): void
    {
        $update = $this->parse(array_replace_recursive(self::privateChatMember(), $changes));

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(TelegramUpdateType::MY_CHAT_MEMBER, $update->type());
        self::assertSame(IgnoredUpdateReason::INVALID_STRUCTURE, $update->reason);
    }

    public static function invalidChatMemberFields(): array
    {
        return [
            'negative date' => [['my_chat_member' => ['date' => -1]]],
            'float date' => [['my_chat_member' => ['date' => 1.0]]],
            'unknown old status' => [['my_chat_member' => ['old_chat_member' => ['status' => 'unknown']]]],
            'unknown new status' => [['my_chat_member' => ['new_chat_member' => ['status' => 'unknown']]]],
            'member mismatch' => [['my_chat_member' => ['new_chat_member' => ['user' => ['id' => 404]]]]],
            'member id string' => [['my_chat_member' => ['new_chat_member' => ['user' => ['id' => '303']]]]],
            'actor id string' => [['my_chat_member' => ['from' => ['id' => '101']]]],
            'actor is bot type' => [['my_chat_member' => ['from' => ['is_bot' => 1]]]],
        ];
    }

    public function testMissingChatMemberActorIsInvalidStructure(): void
    {
        $payload = self::privateChatMember();
        unset($payload['my_chat_member']['from']);

        $update = $this->parse($payload);

        self::assertInstanceOf(IgnoredUpdate::class, $update);
        self::assertSame(IgnoredUpdateReason::INVALID_STRUCTURE, $update->reason);
    }

    private static function privateChatMember(): array
    {
        return [
            'update_id' => 7,
            'my_chat_member' => [
                'chat' => ['id' => 202, 'type' => 'private'],
                'from' => ['id' => 101, 'is_bot' => false, 'first_name' => 'Test'],
                'date' => 1790330400,
                'old_chat_member' => ['user' => ['id' => 303], 'status' => 'member'],
                'new_chat_member' => ['user' => ['id' => 303], 'status' => 'kicked'],
            ],
        ];
    }

    private static function privateMessage(): array
    {
        return [
            'update_id' => 7,
            'message' => [
                'message_id' => 33,
                'chat' => ['id' => 202, 'type' => 'private'],
                'from' => ['id' => 101, 'is_bot' => false, 'first_name' => 'Test'],
                'text' => 'Hello',
            ],
        ];
    }

    private function parse(array $payload): TelegramUpdate
    {
        return (new TelegramUpdateParser())->parse(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
