<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure\rabbitmq;

use Codeception\Test\Unit;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\message\IOutboxPayload;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\infrastructure\rabbitmq\BrokerEnvelopeCodec;
use tests\fixtures\platform\TestOutboxRoutes;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class BrokerEnvelopeCodecTest extends Unit
{
    private const OUTBOX_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac4';
    private const CORRELATION_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac5';
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac6';

    public function testDeterministicRoundTripWithPersistentProperties(): void
    {
        $codec = new BrokerEnvelopeCodec(TestOutboxRoutes::registry());
        $envelope = new BrokerEnvelope(
            self::OUTBOX_ID,
            'telegram.update.received',
            '1.0',
            self::CORRELATION_ID,
            new TelegramUpdateReceivedPayload(self::UPDATE_ID),
        );
        $message = $codec->encode($envelope);
        self::assertSame(
            '{"outbox_id":"01890f4d-3c2a-7f48-8c0b-123456789ac4",'
            . '"message_type":"telegram.update.received","schema_version":"1.0",'
            . '"correlation_id":"01890f4d-3c2a-7f48-8c0b-123456789ac5",'
            . '"payload":{"update_id":"01890f4d-3c2a-7f48-8c0b-123456789ac6"}}',
            $message->getBody(),
        );
        self::assertSame(self::body(), $message->getBody());
        self::assertSame($message->getBody(), $codec->encode($envelope)->getBody());
        self::assertSame(self::properties(), $message->get_properties());
        self::assertEquals($envelope, $codec->decode($message));
    }

    public function testTransportDoesNotDispatchOrAllowlistMessageTypes(): void
    {
        $body = self::fields();
        $body['message_type'] = 'future.command';
        $body['schema_version'] = '2.0';
        $message = new AMQPMessage(json_encode($body, JSON_THROW_ON_ERROR), self::properties());
        $codec = new BrokerEnvelopeCodec(TestOutboxRoutes::registry());
        $envelope = $codec->decode($message);

        self::assertSame('future.command', $envelope->messageType);
        self::assertSame('2.0', $envelope->schemaVersion);
        self::assertSame($message->getBody(), $codec->encode($envelope)->getBody());
    }

    public function testAcceptsEnvelopeAtByteLimit(): void
    {
        $message = new AMQPMessage(str_pad(self::body(), 4096, ' '), self::properties());
        self::assertSame(self::OUTBOX_ID, (new BrokerEnvelopeCodec(TestOutboxRoutes::registry()))->decode($message)->outboxId);
    }

    public function testAcceptsOnlyTypedQuorumDeliveryCountersWithoutChangingEnvelope(): void
    {
        $message = new AMQPMessage(self::body(), self::properties() + [
            'application_headers' => new AMQPTable(['x-delivery-count' => 1, 'x-acquired-count' => 2]),
        ]);
        $codec = new BrokerEnvelopeCodec(TestOutboxRoutes::registry());
        self::assertSame(self::body(), $codec->encode($codec->decode($message))->getBody());
    }

    /** @dataProvider invalidMessages */
    public function testRejectsInvalidWireDataWithSafeError(AMQPMessage $message): void
    {
        try {
            (new BrokerEnvelopeCodec(TestOutboxRoutes::registry()))->decode($message);
            self::fail('Expected invalid envelope.');
        } catch (BrokerTransportException $exception) {
            self::assertSame(BrokerTransportErrorCode::INVALID_ENVELOPE, $exception->errorCode);
            self::assertSame('invalid_envelope', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{AMQPMessage}> */
    public static function invalidMessages(): iterable
    {
        foreach (['{', 'null', '[]', str_pad(self::body(), 4097, ' '), str_repeat('[', 17) . '0' . str_repeat(']', 17)] as $i => $body) {
            yield 'JSON-' . $i => [new AMQPMessage($body, self::properties())];
        }
        $truncated = new AMQPMessage(self::body(), self::properties());
        $truncated->setIsTruncated(true);
        yield 'truncated body' => [$truncated];
        yield 'unapproved headers' => [new AMQPMessage(self::body(), self::properties() + [
            'application_headers' => new AMQPTable(['message_type' => 'synthetic']),
        ])];
        foreach (['x-delivery-count', 'x-acquired-count'] as $header) {
            foreach ([-1, '1'] as $value) {
                yield $header . '-' . get_debug_type($value) => [new AMQPMessage(self::body(), self::properties() + [
                    'application_headers' => new AMQPTable([$header => $value]),
                ])];
            }
        }
        foreach (array_keys(self::fields()) as $key) {
            $fields = self::fields();
            unset($fields[$key]);
            yield 'missing-' . $key => [new AMQPMessage(json_encode($fields, JSON_THROW_ON_ERROR), self::properties())];
        }
        $changes = [
            'unknown field' => ['secret' => 'synthetic'],
            'outbox type' => ['outbox_id' => 42],
            'noncanonical outbox' => ['outbox_id' => strtoupper(self::OUTBOX_ID)],
            'correlation type' => ['correlation_id' => false],
            'invalid correlation' => ['correlation_id' => 'synthetic'],
            'type array' => ['message_type' => []],
            'type blank' => ['message_type' => ' '],
            'type long' => ['message_type' => str_repeat('x', 65)],
            'type control' => ['message_type' => "synthetic\n"],
            'version number' => ['schema_version' => 1.0],
            'version long' => ['schema_version' => str_repeat('x', 49)],
            'payload array' => ['payload' => []],
            'payload unknown' => ['payload' => ['update_id' => self::UPDATE_ID, 'text' => 'synthetic']],
            'payload missing' => ['payload' => new \stdClass()],
            'update type' => ['payload' => ['update_id' => 42]],
            'update noncanonical' => ['payload' => ['update_id' => strtoupper(self::UPDATE_ID)]],
            'payload size' => ['payload' => ['update_id' => str_repeat('x', 1024)]],
        ];
        foreach ($changes as $name => $change) {
            yield $name => [new AMQPMessage(json_encode(array_replace(self::fields(), $change), JSON_THROW_ON_ERROR), self::properties())];
        }
        foreach (self::properties() as $key => $value) {
            $properties = self::properties();
            unset($properties[$key]);
            yield 'missing property-' . $key => [new AMQPMessage(self::body(), $properties)];
            $properties[$key] = is_int($value) ? 1 : 'synthetic';
            yield 'mismatched property-' . $key => [new AMQPMessage(self::body(), $properties)];
        }
    }

    public function testRejectsUnapprovedPayloadInsteadOfPublishingArbitraryFields(): void
    {
        $payload = new class () implements IOutboxPayload {
            public function technicalFields(): array
            {
                return ['text' => 'synthetic'];
            }
        };
        $this->expectException(BrokerTransportException::class);
        $this->expectExceptionMessage('invalid_envelope');
        (new BrokerEnvelopeCodec(TestOutboxRoutes::registry()))->encode(new BrokerEnvelope(
            self::OUTBOX_ID,
            'telegram.update.received',
            '1.0',
            self::CORRELATION_ID,
            $payload,
        ));
    }

    /** @return array<string, string|array{update_id: string}> */
    private static function fields(): array
    {
        return [
            'outbox_id' => self::OUTBOX_ID,
            'message_type' => 'telegram.update.received',
            'schema_version' => '1.0',
            'correlation_id' => self::CORRELATION_ID,
            'payload' => ['update_id' => self::UPDATE_ID],
        ];
    }

    private static function body(): string
    {
        return json_encode(self::fields(), JSON_THROW_ON_ERROR);
    }

    /** @return array{message_id: string, correlation_id: string, content_type: string, delivery_mode: int} */
    private static function properties(): array
    {
        return [
            'message_id' => self::OUTBOX_ID,
            'correlation_id' => self::CORRELATION_ID,
            'content_type' => 'application/json',
            'delivery_mode' => 2,
        ];
    }
}
