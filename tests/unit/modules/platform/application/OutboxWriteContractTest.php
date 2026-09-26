<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Codeception\Test\Unit;
use Error;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\dto\OutboxWriteReceipt;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\TelegramUpdateReceivedPayload;
use ReflectionClass;
use ReflectionProperty;
use TypeError;

final class OutboxWriteContractTest extends Unit
{
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac4';
    private const CORRELATION_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac5';

    public function testBuildsTypedIntent(): void
    {
        $intent = self::intent();

        self::assertSame(self::UPDATE_ID, $intent->aggregateId);
        self::assertSame(['update_id' => self::UPDATE_ID], $intent->payload->technicalFields());
        self::assertSame('synthetic-operation-1', $intent->idempotencyKey);

        $this->expectException(Error::class);
        (new ReflectionProperty(OutboxWriteIntent::class, 'aggregateId'))->setValue($intent, self::CORRELATION_ID);
    }

    public function testReceiptExposesOnlyIdAndOutcome(): void
    {
        $created = new OutboxWriteReceipt(self::UPDATE_ID, OutboxWriteOutcome::CREATED);
        $existing = new OutboxWriteReceipt(self::UPDATE_ID, OutboxWriteOutcome::ALREADY_EXISTS);

        self::assertSame(self::UPDATE_ID, $created->outboxMessageId);
        self::assertSame(OutboxWriteOutcome::CREATED, $created->outcome);
        self::assertSame(OutboxWriteOutcome::ALREADY_EXISTS, $existing->outcome);

        $this->expectException(Error::class);
        (new ReflectionProperty(OutboxWriteReceipt::class, 'outcome'))->setValue(
            $created,
            OutboxWriteOutcome::ALREADY_EXISTS,
        );
    }

    /**
     * @dataProvider invalidIntentFields
     *
     * @param array<string, string> $changes
     */
    public function testRejectsInvalidIntentFields(array $changes): void
    {
        try {
            self::intent($changes);
            self::fail('Expected invalid intent to be rejected.');
        } catch (OutboxWriteException $exception) {
            self::assertSame(OutboxWriteFailure::INVALID_INTENT, $exception->failure);
            foreach ($changes as $value) {
                if (trim($value) !== '') {
                    self::assertStringNotContainsString($value, $exception->getMessage());
                }
            }
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidIntentFields(): iterable
    {
        yield 'blank owner' => [['ownerModule' => '   ']];
        yield 'long owner' => [['ownerModule' => str_repeat('x', 33)]];
        yield 'blank type' => [['messageType' => '']];
        yield 'long type' => [['messageType' => str_repeat('x', 65)]];
        yield 'long version' => [['schemaVersion' => str_repeat('x', 49)]];
        yield 'long aggregate type' => [['aggregateType' => str_repeat('x', 33)]];
        yield 'invalid aggregate UUID' => [['aggregateId' => 'not-a-uuid']];
        yield 'blank idempotency key' => [['idempotencyKey' => '   ']];
        yield 'long idempotency key' => [['idempotencyKey' => str_repeat('x', 201)]];
        yield 'invalid correlation UUID' => [['correlationId' => 'not-a-uuid']];
    }

    public function testRejectsInvalidPayloadWithoutLeakingInput(): void
    {
        $invalidId = 'raw-telegram-update';

        try {
            new TelegramUpdateReceivedPayload($invalidId);
            self::fail('Expected invalid payload to be rejected.');
        } catch (OutboxWriteException $exception) {
            self::assertSame(OutboxWriteFailure::INVALID_INTENT, $exception->failure);
            self::assertStringNotContainsString($invalidId, $exception->getMessage());
        }
    }

    public function testIdempotencyKeyLimitCountsCharacters(): void
    {
        $key = str_repeat('x', 199) . "\u{00F8}";

        self::assertSame($key, self::intent(['idempotencyKey' => $key])->idempotencyKey);
    }

    public function testRejectsInvalidReceiptId(): void
    {
        $this->expectException(OutboxWriteException::class);
        $this->expectExceptionMessage(OutboxWriteFailure::INVALID_INTENT->value);

        new OutboxWriteReceipt('not-a-uuid', OutboxWriteOutcome::CREATED);
    }

    public function testRejectsRawUpdateArray(): void
    {
        $this->expectException(TypeError::class);

        (new ReflectionClass(OutboxWriteIntent::class))->newInstanceArgs([
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            self::UPDATE_ID,
            ['message' => ['text' => 'synthetic']],
            'synthetic-operation-1',
            self::CORRELATION_ID,
        ]);
    }

    public function testFailureCodesAreSafeAndMachineReadable(): void
    {
        foreach (OutboxWriteFailure::cases() as $failure) {
            $exception = new OutboxWriteException($failure);
            self::assertSame($failure, $exception->failure);
            self::assertSame($failure->value, $exception->getMessage());
        }
    }

    /** @param array<string, string> $changes */
    private static function intent(array $changes = []): OutboxWriteIntent
    {
        $fields = array_replace([
            'ownerModule' => 'Telegram',
            'messageType' => 'telegram.update.received',
            'schemaVersion' => '1.0',
            'aggregateType' => 'TELEGRAM_UPDATE',
            'aggregateId' => self::UPDATE_ID,
            'idempotencyKey' => 'synthetic-operation-1',
            'correlationId' => self::CORRELATION_ID,
        ], $changes);

        return new OutboxWriteIntent(
            $fields['ownerModule'],
            $fields['messageType'],
            $fields['schemaVersion'],
            $fields['aggregateType'],
            $fields['aggregateId'],
            new TelegramUpdateReceivedPayload(self::UPDATE_ID),
            $fields['idempotencyKey'],
            $fields['correlationId'],
        );
    }
}
