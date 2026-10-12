<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\application;

use Codeception\Test\Unit;
use InvalidArgumentException;
use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\dto\TelegramUpdateAcceptanceReceipt;
use modules\telegram\application\enum\IncomingTelegramUpdateType;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use modules\telegram\application\enum\TelegramUpdateIgnoreReason;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\telegram\infrastructure\transport\update\TelegramUpdateParser;
use RuntimeException;

final class TelegramUpdateAcceptanceContractTest extends Unit
{
    public function testSupportedAndIgnoredCommandsRetainOnlyAcceptanceMetadata(): void
    {
        $command = new AcceptTelegramUpdateCommand(
            'test-bot',
            0,
            IncomingTelegramUpdateType::MESSAGE,
            '202',
            null,
            '{}',
            hash('sha256', '{}'),
        );
        self::assertSame('test-bot', $command->botKey);
        self::assertSame(0, $command->telegramUpdateId);
        self::assertSame('202', $command->chatId);
        self::assertSame('telegram.update/1.0', $command->payloadSchemaVersion);
        self::assertNull($command->ignoredReason);

        $ignored = new AcceptTelegramUpdateCommand(
            'test-bot',
            1,
            IncomingTelegramUpdateType::UNSUPPORTED,
            null,
            TelegramUpdateIgnoreReason::UNSUPPORTED_UPDATE_TYPE,
            '{}',
            hash('sha256', '{}'),
        );
        self::assertNull($ignored->chatId);
        self::assertSame(TelegramUpdateIgnoreReason::UNSUPPORTED_UPDATE_TYPE, $ignored->ignoredReason);
        self::assertSame(TelegramUpdateParser::MAX_PAYLOAD_BYTES, AcceptTelegramUpdateCommand::MAX_PAYLOAD_BYTES);
    }

    /** @dataProvider validChatIds */
    public function testAcceptsSignedBigintBoundariesAndPayloadLimit(string $chatId): void
    {
        $body = str_repeat(' ', 65_536);
        $command = new AcceptTelegramUpdateCommand(
            str_repeat('я', 64),
            PHP_INT_MAX,
            IncomingTelegramUpdateType::MESSAGE,
            $chatId,
            null,
            $body,
            hash('sha256', $body),
        );
        self::assertSame($chatId, $command->chatId);
        self::assertSame($body, $command->rawPayload);
    }

    public static function validChatIds(): iterable
    {
        yield ['1'];
        yield ['-1'];
        yield ['9223372036854775807'];
        yield ['-9223372036854775808'];
    }

    /** @dataProvider invalidCommands */
    public function testRejectsInvalidMetadataWithoutDisclosingInput(
        string $botKey,
        int $updateId,
        string $type,
        ?string $chatId,
        ?string $reason,
        string $body,
        string $hash,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_telegram_update_acceptance_command');
        new AcceptTelegramUpdateCommand(
            $botKey,
            $updateId,
            IncomingTelegramUpdateType::from($type),
            $chatId,
            $reason === null ? null : TelegramUpdateIgnoreReason::from($reason),
            $body,
            $hash,
        );
    }

    public static function invalidCommands(): iterable
    {
        $valid = ['test-bot', 1, 'MESSAGE', '202', null, '{}', hash('sha256', '{}')];
        foreach (['', ' test-bot', "test-bot\n", str_repeat('я', 65), "\xFF"] as $value) {
            yield array_replace($valid, [0 => $value]);
        }
        yield array_replace($valid, [1 => -1]);
        foreach ([null, '', '0', '-0', '+1', '01', '-01', '1.0', '1e2', ' 1', "1\n",
            '9223372036854775808', '-9223372036854775809', str_repeat('9', 40)] as $value) {
            yield array_replace($valid, [3 => $value]);
        }
        yield array_replace($valid, [2 => 'UNSUPPORTED']);
        yield array_replace($valid, [4 => 'NON_PRIVATE_CHAT']);
        foreach (['', str_repeat(' ', 65_537)] as $value) {
            yield array_replace($valid, [5 => $value]);
        }
        foreach (['', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64), str_repeat('g', 64)] as $value) {
            yield array_replace($valid, [6 => $value]);
        }
    }

    public function testReceiptRetainsInboxUuidWithoutChangingBrokerPayloadSemantics(): void
    {
        $id = '01960000-0000-7000-8000-000000000001';
        foreach (TelegramUpdateAcceptanceOutcome::cases() as $outcome) {
            $receipt = new TelegramUpdateAcceptanceReceipt($id, $outcome);
            self::assertSame(['inboxId' => $id, 'outcome' => $outcome], get_object_vars($receipt));
            self::assertSame(['update_id' => $id], (new TelegramUpdateReceivedPayload($receipt->inboxId))->technicalFields());
        }
    }

    /** @dataProvider invalidInboxIds */
    public function testReceiptRejectsNonCanonicalOrExternalId(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_telegram_update_acceptance_receipt');
        new TelegramUpdateAcceptanceReceipt($id, TelegramUpdateAcceptanceOutcome::ACCEPTED);
    }

    public static function invalidInboxIds(): iterable
    {
        yield ['123'];
        yield [''];
        yield ['0196AAAA-0000-7000-8000-000000000001'];
        yield ['01960000000070008000000000000001'];
    }

    public function testUnavailablePreservesInternalCauseWithFixedPublicMessage(): void
    {
        $cause = new RuntimeException('synthetic-internal-cause');
        $exception = new TelegramUpdateAcceptanceUnavailableException($cause);
        self::assertSame('telegram_update_acceptance_unavailable', $exception->getMessage());
        self::assertSame($cause, $exception->getPrevious());
    }
}
