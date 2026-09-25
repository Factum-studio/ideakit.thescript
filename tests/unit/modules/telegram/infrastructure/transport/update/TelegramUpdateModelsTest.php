<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\transport\update;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Error;
use InvalidArgumentException;
use modules\telegram\infrastructure\transport\update\CallbackQueryUpdate;
use modules\telegram\infrastructure\transport\update\IgnoredUpdate;
use modules\telegram\infrastructure\transport\update\IgnoredUpdateReason;
use modules\telegram\infrastructure\transport\update\MessageUpdate;
use modules\telegram\infrastructure\transport\update\MyChatMemberUpdate;
use modules\telegram\infrastructure\transport\update\TelegramChatMemberStatus;
use modules\telegram\infrastructure\transport\update\TelegramSenderSnapshot;
use modules\telegram\infrastructure\transport\update\TelegramUpdateType;

final class TelegramUpdateModelsTest extends Unit
{
    public function testMessageRetainsDistinctSenderAndChatWithOptionalSnapshotFields(): void
    {
        $sender = new TelegramSenderSnapshot('101', 'Test', null, null, null);
        $update = new MessageUpdate(11, '202', $sender, 33, 'Hello');

        self::assertSame(11, $update->updateId());
        self::assertSame(TelegramUpdateType::MESSAGE, $update->type());
        self::assertSame('202', $update->chatId);
        self::assertSame('101', $update->sender->userId);
        self::assertSame('Hello', $update->text);
        self::assertNull($update->sender->username);
        self::assertNull($update->sender->lastName);
        self::assertNull($update->sender->languageCode);
    }

    public function testCallbackKeepsOpaqueDataUnverified(): void
    {
        $update = new CallbackQueryUpdate(
            12,
            '202',
            new TelegramSenderSnapshot('101', 'Test', 'tester', null, 'en'),
            'opaque-query',
            33,
            'unverified-input',
        );

        self::assertSame(TelegramUpdateType::CALLBACK_QUERY, $update->type());
        self::assertSame('opaque-query', $update->callbackQueryId);
        self::assertSame(33, $update->messageId);
        self::assertSame('unverified-input', $update->unverifiedData);
        self::assertFalse(method_exists($update, 'action'));
        self::assertFalse(method_exists($update, 'signatureValid'));
    }

    public function testChatMemberEventRetainsDistinctActorMemberAndUtcTime(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-25 10:00:00', new DateTimeZone('UTC'));
        $update = new MyChatMemberUpdate(
            13,
            '202',
            '101',
            '303',
            TelegramChatMemberStatus::MEMBER,
            TelegramChatMemberStatus::KICKED,
            $occurredAt,
        );

        self::assertSame(TelegramUpdateType::MY_CHAT_MEMBER, $update->type());
        self::assertSame('202', $update->chatId);
        self::assertSame('101', $update->actorUserId);
        self::assertSame('303', $update->changedMemberUserId);
        self::assertSame(TelegramChatMemberStatus::MEMBER, $update->oldStatus);
        self::assertSame(TelegramChatMemberStatus::KICKED, $update->newStatus);
        self::assertSame($occurredAt, $update->occurredAt);
    }

    public function testIgnoredUpdatePreservesSourceTypeWithoutPersonalFields(): void
    {
        $groupMessage = new IgnoredUpdate(
            14,
            TelegramUpdateType::MESSAGE,
            IgnoredUpdateReason::NON_PRIVATE_CHAT,
        );
        $unknown = new IgnoredUpdate(
            15,
            TelegramUpdateType::UNSUPPORTED,
            IgnoredUpdateReason::UNSUPPORTED_UPDATE_TYPE,
        );

        self::assertSame(TelegramUpdateType::MESSAGE, $groupMessage->type());
        self::assertSame(IgnoredUpdateReason::NON_PRIVATE_CHAT, $groupMessage->reason);
        self::assertSame(TelegramUpdateType::UNSUPPORTED, $unknown->type());
        self::assertSame(15, $unknown->updateId());
        self::assertSame(['sourceType', 'reason'], array_keys(get_object_vars($groupMessage)));
    }

    public function testClosedVocabulariesMatchTransportContracts(): void
    {
        self::assertSame(
            ['MESSAGE', 'CALLBACK_QUERY', 'MY_CHAT_MEMBER', 'UNSUPPORTED'],
            array_map(static fn (TelegramUpdateType $type): string => $type->value, TelegramUpdateType::cases()),
        );
        self::assertSame(
            ['creator', 'administrator', 'member', 'restricted', 'left', 'kicked'],
            array_map(
                static fn (TelegramChatMemberStatus $status): string => $status->value,
                TelegramChatMemberStatus::cases(),
            ),
        );
        self::assertSame(
            [
                'NON_PRIVATE_CHAT',
                'UNSUPPORTED_UPDATE_TYPE',
                'UNSUPPORTED_CONTENT',
                'UNSUPPORTED_CALLBACK_CONTEXT',
                'INVALID_STRUCTURE',
            ],
            array_map(
                static fn (IgnoredUpdateReason $reason): string => $reason->value,
                IgnoredUpdateReason::cases(),
            ),
        );
    }

    public function testSnapshotCannotBeChangedAfterConstruction(): void
    {
        $sender = new TelegramSenderSnapshot('101', 'Test', null, null, null);

        $this->expectException(Error::class);
        $sender->firstName = 'Changed';
    }

    public function testRejectsNegativeUpdateId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_update_id');

        new IgnoredUpdate(-1, TelegramUpdateType::MESSAGE, IgnoredUpdateReason::NON_PRIVATE_CHAT);
    }

    public function testRejectsEmptyCallbackQueryIdWithoutEchoingData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_callback_query_id');

        new CallbackQueryUpdate(
            12,
            '202',
            new TelegramSenderSnapshot('101', 'Test', null, null, null),
            '',
            33,
            'unverified-input',
        );
    }

    public function testRejectsNonUtcChatMemberTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('occurred_at_must_be_utc');

        new MyChatMemberUpdate(
            13,
            '202',
            '101',
            '303',
            TelegramChatMemberStatus::MEMBER,
            TelegramChatMemberStatus::KICKED,
            new DateTimeImmutable('2026-09-25 15:00:00', new DateTimeZone('Asia/Yekaterinburg')),
        );
    }
}
