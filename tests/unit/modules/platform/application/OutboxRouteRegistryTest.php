<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Codeception\Test\Unit;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\IOutboxPayload;
use modules\platform\application\route\OutboxRoute;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\route\OutboxRouteRegistry;
use InvalidArgumentException;
use tests\fixtures\platform\TestOutboxRoutes;

final class OutboxRouteRegistryTest extends Unit
{
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac4';
    private const OTHER_UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac6';

    public function testResolvesTheOnlyApprovedRoute(): void
    {
        $intent = self::intent();
        $route = TestOutboxRoutes::registry()->resolve($intent);

        self::assertSame('RABBITMQ', $route->destination);
        self::assertSame('critical', $route->routingKey);
        self::assertSame(1024, $route->maximumPayloadBytes);
        self::assertSame('Telegram', $route->ownerModule);
        self::assertSame('telegram.update.received', $route->messageType);
        self::assertSame('1.0', $route->schemaVersion);
        self::assertSame('TELEGRAM_UPDATE', $route->aggregateType);
        self::assertLessThanOrEqual(
            $route->maximumPayloadBytes,
            strlen(json_encode($intent->payload->technicalFields(), JSON_THROW_ON_ERROR)),
        );
    }

    public function testRegistryRequiresExplicitNonemptyRoutes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outbox_route_invalid');

        new OutboxRouteRegistry([], ['critical']);
    }

    /** @dataProvider conflictingRegistrations */
    public function testRejectsDuplicateOrContradictoryRegistration(OutboxRoute $other): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outbox_route_invalid');

        new OutboxRouteRegistry([TestOutboxRoutes::telegram(), $other], ['critical']);
    }

    public function testAlternativeRoutingKeyRequiresExplicitBrokerSupport(): void
    {
        $alternative = new OutboxRoute(
            'Synthetic',
            'synthetic.command',
            '1.0',
            'SYNTHETIC',
            'RABBITMQ',
            'other',
            1024,
            TestOutboxRoutes::telegram()->payloadCodec,
        );

        self::assertSame('other', $alternative->routingKey);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outbox_route_invalid');

        new OutboxRouteRegistry([$alternative], ['critical']);
    }

    /** @dataProvider invalidRoutingKeys */
    public function testRouteRejectsInvalidRoutingKey(string $routingKey): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outbox_route_invalid');

        new OutboxRoute('Synthetic', 'synthetic.command', '1.0', 'SYNTHETIC', 'RABBITMQ', $routingKey, 1024, TestOutboxRoutes::telegram()->payloadCodec);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRoutingKeys(): iterable
    {
        yield 'blank' => [''];
        yield 'invalid characters' => ['other/key'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    /** @dataProvider invalidSupportedRoutingKeys */
    public function testRegistryRejectsInvalidSupportedRoutingKeys(array $routingKeys): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outbox_route_invalid');

        new OutboxRouteRegistry([TestOutboxRoutes::telegram()], $routingKeys);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidSupportedRoutingKeys(): iterable
    {
        yield 'empty' => [[]];
        yield 'duplicate' => [['critical', 'critical']];
        yield 'invalid' => [['critical', 'other/key']];
        yield 'not a list' => [[1 => 'critical']];
    }

    /** @return iterable<string, array{OutboxRoute}> */
    public static function conflictingRegistrations(): iterable
    {
        yield 'duplicate' => [TestOutboxRoutes::telegram()];
        yield 'contradictory metadata' => [new OutboxRoute(
            'Other',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            'RABBITMQ',
            'critical',
            1024,
            TestOutboxRoutes::telegram()->payloadCodec,
        )];
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

        TestOutboxRoutes::registry()->resolve(self::intent($changes));
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

        TestOutboxRoutes::registry()->resolve(self::intent(['aggregateId' => self::OTHER_UPDATE_ID]));
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

        TestOutboxRoutes::registry()->resolve(self::intent(payload: $payload));
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
