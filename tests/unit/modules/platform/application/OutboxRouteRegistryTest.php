<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Codeception\Test\Unit;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\IOutboxPayload;
use modules\platform\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\route\OutboxRouteRegistry;

final class OutboxRouteRegistryTest extends Unit
{
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac4';
    private const OTHER_UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac6';

    public function testResolvesTheOnlyApprovedRoute(): void
    {
        $intent = self::intent();
        $route = (new OutboxRouteRegistry())->resolve($intent);

        self::assertSame('RABBITMQ', $route->destination);
        self::assertSame('critical', $route->routingKey);
        self::assertSame(1024, $route->maximumPayloadBytes);
        self::assertLessThanOrEqual(
            $route->maximumPayloadBytes,
            strlen(json_encode($intent->payload->technicalFields(), JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @dataProvider unsupportedRoutes
     *
     * @param array<string, string> $changes
     */
    public function testRejectsUnsupportedRoute(array $changes): void
    {
        $this->expectException(OutboxWriteException::class);
        $this->expectExceptionMessage(OutboxWriteFailure::UNSUPPORTED_ROUTE->value);

        (new OutboxRouteRegistry())->resolve(self::intent($changes));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function unsupportedRoutes(): iterable
    {
        yield 'unknown owner' => [['ownerModule' => 'Other']];
        yield 'unknown message type' => [['messageType' => 'other.message']];
        yield 'unknown version' => [['schemaVersion' => '2.0']];
        yield 'unknown aggregate type' => [['aggregateType' => 'OTHER_UPDATE']];
    }

    public function testRejectsMismatchedAggregateAndPayload(): void
    {
        $this->expectException(OutboxWriteException::class);
        $this->expectExceptionMessage(OutboxWriteFailure::INVALID_INTENT->value);

        (new OutboxRouteRegistry())->resolve(self::intent(['aggregateId' => self::OTHER_UPDATE_ID]));
    }

    public function testRejectsAlternativePayloadWithExtraFields(): void
    {
        $payload = new class (self::UPDATE_ID) implements IOutboxPayload {
            public function __construct(private string $updateId)
            {
            }

            /** @return array<string, string> */
            public function technicalFields(): array
            {
                return [
                    'update_id' => $this->updateId,
                    'raw_update' => 'synthetic',
                ];
            }
        };

        $this->expectException(OutboxWriteException::class);
        $this->expectExceptionMessage(OutboxWriteFailure::UNSUPPORTED_ROUTE->value);

        (new OutboxRouteRegistry())->resolve(self::intent(payload: $payload));
    }

    /**
     * @param array<string, string> $changes
     */
    private static function intent(array $changes = [], ?IOutboxPayload $payload = null): OutboxWriteIntent
    {
        $fields = array_replace([
            'ownerModule' => 'Telegram',
            'messageType' => 'telegram.update.received',
            'schemaVersion' => '1.0',
            'aggregateType' => 'TELEGRAM_UPDATE',
            'aggregateId' => self::UPDATE_ID,
        ], $changes);

        return new OutboxWriteIntent(
            $fields['ownerModule'],
            $fields['messageType'],
            $fields['schemaVersion'],
            $fields['aggregateType'],
            $fields['aggregateId'],
            $payload ?? new TelegramUpdateReceivedPayload(self::UPDATE_ID),
            'synthetic-operation-1',
            '01890f4d-3c2a-7f48-8c0b-123456789ac5',
        );
    }
}
