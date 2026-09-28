<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure\rabbitmq;

use Codeception\Test\Unit;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\infrastructure\rabbitmq\PublishConfirmation;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPTimeoutException;

final class PublishConfirmationTest extends Unit
{
    public function testAckWithoutReturnCompletesConfirmation(): void
    {
        $confirmation = new PublishConfirmation();
        $calls = 0;
        $confirmation->await(function (float $remaining) use ($confirmation, &$calls): void {
            self::assertSame(5.0, $remaining);
            ++$calls;
            $confirmation->acknowledge();
        }, static fn (): float => 10.0, 5.0);

        self::assertSame(1, $calls);
    }

    /** @dataProvider failures */
    public function testFailureIsSafeAndNeverRetries(string $event, BrokerTransportErrorCode $expected): void
    {
        $confirmation = new PublishConfirmation();
        $calls = 0;
        try {
            $confirmation->await(function (float $remaining) use ($confirmation, $event, &$calls): void {
                ++$calls;
                if ($event === 'return-ack') {
                    $confirmation->returned();
                    $confirmation->acknowledge();
                } elseif ($event === 'nack') {
                    $confirmation->reject();
                } elseif ($event === 'timeout') {
                    throw new AMQPTimeoutException('synthetic-private-context');
                } else {
                    throw new AMQPConnectionClosedException('synthetic-private-context');
                }
            }, static fn (): float => 10.0, 5.0);
            self::fail('Expected failed confirmation.');
        } catch (BrokerTransportException $exception) {
            self::assertSame($expected, $exception->errorCode);
            self::assertSame($expected->value, $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertSame(1, $calls);
        }
    }

    /** @return iterable<string, array{string, BrokerTransportErrorCode}> */
    public static function failures(): iterable
    {
        yield 'return then ack' => ['return-ack', BrokerTransportErrorCode::UNROUTABLE];
        yield 'nack' => ['nack', BrokerTransportErrorCode::NACKED];
        yield 'timeout' => ['timeout', BrokerTransportErrorCode::CONFIRM_TIMEOUT];
        yield 'connection lost' => ['connection', BrokerTransportErrorCode::CONNECTION_FAILURE];
    }

    public function testDeadlineIsNotExtendedByIncomingEvents(): void
    {
        $confirmation = new PublishConfirmation();
        $now = 10.0;
        $waits = [];
        try {
            $confirmation->await(function (float $remaining) use (&$now, &$waits): void {
                $waits[] = $remaining;
                $now += 2.0;
            }, static function () use (&$now): float {
                return $now;
            }, 5.0);
            self::fail('Expected bounded confirmation wait.');
        } catch (BrokerTransportException $exception) {
            self::assertSame(BrokerTransportErrorCode::CONFIRM_TIMEOUT, $exception->errorCode);
            self::assertSame([5.0, 3.0, 1.0], $waits);
        }
    }
}
