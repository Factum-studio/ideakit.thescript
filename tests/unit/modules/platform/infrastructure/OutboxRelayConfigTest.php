<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\infrastructure\config\OutboxRelayConfig;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;

final class OutboxRelayConfigTest extends Unit
{
    public function testDefaultsCoverCompletePublicationAndFinalization(): void
    {
        $broker = self::broker();
        $config = OutboxRelayConfig::fromEnvironment([], $broker);
        self::assertSame(10, $config->defaultLimit);
        self::assertSame(5, $config->settings->maxAttempts);
        self::assertSame(600, $config->settings->leaseSeconds);
        self::assertSame(15, $config->settings->retryBaseSeconds);
        self::assertSame(900, $config->settings->retryMaxSeconds);
        self::assertSame(282.0, $broker->publicationTimeoutSeconds());
    }

    public function testMaximumTimeoutBudgetRequiresLongerLease(): void
    {
        $broker = new RabbitMqConnectionConfig('synthetic', 5672, 'synthetic', 'synthetic-test-only', 'synthetic', 30, 30, 10, 120, 120, 30);
        self::assertSame(1440.0, $broker->publicationTimeoutSeconds());
        $config = OutboxRelayConfig::fromEnvironment(['OUTBOX_RELAY_LEASE_SECONDS' => '1470'], $broker);
        self::assertSame(1470, $config->settings->leaseSeconds);
        $this->expectException(OutboxRelayException::class);
        OutboxRelayConfig::fromEnvironment(['OUTBOX_RELAY_LEASE_SECONDS' => '1469'], $broker);
    }

    /** @dataProvider invalidSettings
     * @param array<string, mixed> $environment
     */
    public function testRejectsInvalidSettingsWithoutExposingInput(array $environment): void
    {
        try {
            OutboxRelayConfig::fromEnvironment($environment, self::broker());
            self::fail('Expected invalid relay configuration.');
        } catch (OutboxRelayException $exception) {
            self::assertSame(OutboxRelayError::CONFIGURATION_INVALID, $exception->error);
            self::assertSame('configuration_invalid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSettings(): iterable
    {
        foreach (['', 'abc', '1.5', '-1', '0', '101', '999999999999999999999999', true, [], 10] as $index => $value) {
            yield 'invalid limit ' . $index => [['OUTBOX_RELAY_LIMIT' => $value]];
        }
        yield 'too few attempts' => [['OUTBOX_RELAY_MAX_ATTEMPTS' => '0']];
        yield 'too many attempts' => [['OUTBOX_RELAY_MAX_ATTEMPTS' => '11']];
        yield 'short lease' => [['OUTBOX_RELAY_LEASE_SECONDS' => '311']];
        yield 'long lease' => [['OUTBOX_RELAY_LEASE_SECONDS' => '3601']];
        yield 'small base' => [['OUTBOX_RELAY_RETRY_BASE_SECONDS' => '0']];
        yield 'large base' => [['OUTBOX_RELAY_RETRY_BASE_SECONDS' => '301']];
        yield 'retry cap below base' => [['OUTBOX_RELAY_RETRY_MAX_SECONDS' => '14']];
        yield 'large retry cap' => [['OUTBOX_RELAY_RETRY_MAX_SECONDS' => '3601']];
    }

    private static function broker(): RabbitMqConnectionConfig
    {
        return new RabbitMqConnectionConfig('synthetic', 5672, 'synthetic', 'synthetic-test-only', 'synthetic');
    }
}
