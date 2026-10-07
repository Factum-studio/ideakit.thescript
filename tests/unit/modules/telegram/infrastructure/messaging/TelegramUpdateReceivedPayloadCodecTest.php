<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\messaging;

use Codeception\Test\Unit;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\IOutboxPayload;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\telegram\infrastructure\messaging\TelegramUpdateReceivedPayloadCodec;

final class TelegramUpdateReceivedPayloadCodecTest extends Unit
{
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac6';

    public function testRestoresOnlyTheInternalUpdateId(): void
    {
        $codec = new TelegramUpdateReceivedPayloadCodec();
        $payload = $codec->decode(['update_id' => self::UPDATE_ID]);

        self::assertInstanceOf(TelegramUpdateReceivedPayload::class, $payload);
        self::assertSame(['update_id' => self::UPDATE_ID], $payload->technicalFields());
        self::assertSame(self::UPDATE_ID, $codec->aggregateId($payload));
        self::assertTrue($codec->accepts($payload));
    }

    /** @dataProvider invalidFields */
    public function testRejectsInvalidTechnicalFields(array $fields): void
    {
        $this->expectException(OutboxWriteException::class);
        $this->expectExceptionMessage(OutboxWriteFailure::INVALID_INTENT->value);

        (new TelegramUpdateReceivedPayloadCodec())->decode($fields);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidFields(): iterable
    {
        yield 'missing' => [[]];
        yield 'extra' => [['update_id' => self::UPDATE_ID, 'raw_update' => 'synthetic']];
        yield 'numeric' => [['update_id' => 42]];
        yield 'noncanonical' => [['update_id' => strtoupper(self::UPDATE_ID)]];
        yield 'oversized' => [['update_id' => str_repeat('x', 1024)]];
    }

    public function testDoesNotAcceptAnotherPayloadImplementation(): void
    {
        $payload = new class (self::UPDATE_ID) implements IOutboxPayload {
            public function __construct(private string $updateId)
            {
            }

            public function technicalFields(): array
            {
                return ['update_id' => $this->updateId];
            }
        };

        self::assertFalse((new TelegramUpdateReceivedPayloadCodec())->accepts($payload));
    }
}
