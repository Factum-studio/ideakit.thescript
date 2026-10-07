<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Codeception\Test\Unit;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IRetryJitter;

final class OutboxRetryPolicyTest extends Unit
{
    /** @dataProvider transportFailures */
    public function testMapsPublisherFailure(BrokerTransportErrorCode $code, OutboxRelayError $expected): void
    {
        self::assertSame($expected, self::policy()->mapTransportFailure($code));
    }

    /** @return iterable<string, array{BrokerTransportErrorCode, OutboxRelayError}> */
    public static function transportFailures(): iterable
    {
        yield 'invalid envelope' => [BrokerTransportErrorCode::INVALID_ENVELOPE, OutboxRelayError::INVALID_MESSAGE];
        yield 'connection' => [BrokerTransportErrorCode::CONNECTION_FAILURE, OutboxRelayError::CONNECTION_FAILURE];
        yield 'nack' => [BrokerTransportErrorCode::NACKED, OutboxRelayError::NACKED];
        yield 'timeout' => [BrokerTransportErrorCode::CONFIRM_TIMEOUT, OutboxRelayError::CONFIRM_TIMEOUT];
        yield 'return' => [BrokerTransportErrorCode::UNROUTABLE, OutboxRelayError::UNROUTABLE];
        yield 'configuration' => [BrokerTransportErrorCode::CONFIGURATION_INVALID, OutboxRelayError::CONFIGURATION_INVALID];
        yield 'topology' => [BrokerTransportErrorCode::TOPOLOGY_MISMATCH, OutboxRelayError::TOPOLOGY_MISMATCH];
    }

    /** @dataProvider consumerFailures */
    public function testConsumerFailureStopsRelay(BrokerTransportErrorCode $code): void
    {
        $this->expectException(OutboxRelayException::class);
        $this->expectExceptionMessage('unexpected_failure');
        self::policy()->mapTransportFailure($code);
    }

    /** @return iterable<string, array{BrokerTransportErrorCode}> */
    public static function consumerFailures(): iterable
    {
        yield 'settled' => [BrokerTransportErrorCode::DELIVERY_ALREADY_SETTLED];
        yield 'unavailable' => [BrokerTransportErrorCode::DELIVERY_UNAVAILABLE];
    }

    /** @dataProvider decisions */
    public function testClassifiesFailureAndBoundsDelay(
        OutboxRelayError $error,
        int $attempt,
        int $maximum,
        bool $maximumJitter,
        string $status,
        ?int $delay,
    ): void {
        $decision = self::policy($maximumJitter)->decide($error, $attempt, $maximum);

        self::assertSame($status, $decision->status);
        self::assertSame($error, $decision->error);
        self::assertSame($delay, $decision->delaySeconds);
    }

    /** @return iterable<string, array{OutboxRelayError, int, int, bool, string, ?int}> */
    public static function decisions(): iterable
    {
        foreach ([OutboxRelayError::CONNECTION_FAILURE, OutboxRelayError::NACKED, OutboxRelayError::CONFIRM_TIMEOUT] as $error) {
            yield $error->value . ' retry' => [$error, 1, 5, false, 'RETRY_SCHEDULED', 15];
            yield $error->value . ' last attempt' => [$error, 5, 5, true, 'FAILED', null];
        }
        yield 'first upper jitter' => [OutboxRelayError::NACKED, 1, 10, true, 'RETRY_SCHEDULED', 18];
        yield 'second lower jitter' => [OutboxRelayError::NACKED, 2, 10, false, 'RETRY_SCHEDULED', 30];
        yield 'second upper jitter' => [OutboxRelayError::NACKED, 2, 10, true, 'RETRY_SCHEDULED', 36];
        yield 'capped delay' => [OutboxRelayError::NACKED, 9, 10, true, 'RETRY_SCHEDULED', 900];
        yield 'expired lease retry' => [OutboxRelayError::LEASE_EXPIRED, 2, 5, true, 'RETRY_SCHEDULED', 36];
        yield 'expired lease exhausted' => [OutboxRelayError::LEASE_EXPIRED, 5, 5, true, 'FAILED', null];
        foreach ([
            OutboxRelayError::INVALID_MESSAGE, OutboxRelayError::UNSUPPORTED_ROUTE,
            OutboxRelayError::ATTEMPT_LIMIT_REACHED, OutboxRelayError::CONFIRM_MISMATCH,
            OutboxRelayError::UNROUTABLE,
        ] as $error) {
            yield $error->value => [$error, 1, 5, true, 'FAILED', null];
        }
    }

    public function testJitterCannotExceedRemainingCap(): void
    {
        $policy = self::policy(true, new OutboxRelaySettings(5, 600, 15, 31));
        self::assertSame(31, $policy->decide(OutboxRelayError::NACKED, 2, 5)->delaySeconds);
    }

    /** @dataProvider operationalFailures */
    public function testOperationalFailureStopsWithoutMessageDecision(OutboxRelayError $error): void
    {
        try {
            self::policy()->decide($error, 1, 5);
            self::fail('Expected operational failure.');
        } catch (OutboxRelayException $exception) {
            self::assertSame($error, $exception->error);
            self::assertSame($error->value, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{OutboxRelayError}> */
    public static function operationalFailures(): iterable
    {
        foreach ([
            OutboxRelayError::CONFIGURATION_INVALID, OutboxRelayError::TOPOLOGY_MISMATCH,
            OutboxRelayError::PERSISTENCE_FAILURE, OutboxRelayError::TRANSACTION_ALREADY_ACTIVE,
            OutboxRelayError::UNEXPECTED_FAILURE,
        ] as $error) {
            yield $error->value => [$error];
        }
    }

    /** @dataProvider invalidBudgets */
    public function testRejectsInvalidAttemptBudget(int $attempt, int $maximum): void
    {
        $this->expectException(OutboxRelayException::class);
        $this->expectExceptionMessage('configuration_invalid');
        self::policy()->decide(OutboxRelayError::NACKED, $attempt, $maximum);
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidBudgets(): iterable
    {
        yield 'zero attempt' => [0, 5];
        yield 'negative attempt' => [-1, 5];
        yield 'zero budget' => [1, 0];
        yield 'oversized budget' => [1, 11];
    }

    /** @dataProvider invalidJitter */
    public function testRejectsJitterOutsideRequestedRange(int $value): void
    {
        $jitter = new class ($value) implements IRetryJitter {
            public function __construct(private readonly int $value)
            {
            }

            public function between(int $minimum, int $maximum): int
            {
                return $this->value;
            }
        };
        $policy = new OutboxRetryPolicy(new OutboxRelaySettings(5, 600, 15, 900), $jitter);

        $this->expectException(OutboxRelayException::class);
        $this->expectExceptionMessage('unexpected_failure');
        $policy->decide(OutboxRelayError::NACKED, 1, 5);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidJitter(): iterable
    {
        yield 'below minimum' => [-1];
        yield 'above maximum' => [4];
    }

    private static function policy(bool $maximumJitter = false, ?OutboxRelaySettings $settings = null): OutboxRetryPolicy
    {
        $jitter = new class ($maximumJitter) implements IRetryJitter {
            public function __construct(private readonly bool $maximumJitter)
            {
            }

            public function between(int $minimum, int $maximum): int
            {
                return $this->maximumJitter ? $maximum : $minimum;
            }
        };

        return new OutboxRetryPolicy($settings ?? new OutboxRelaySettings(5, 600, 15, 900), $jitter);
    }
}
