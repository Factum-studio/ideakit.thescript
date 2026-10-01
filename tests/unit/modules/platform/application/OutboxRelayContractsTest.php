<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\platform\application\command\RelayOutboxCommand;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelayReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;

final class OutboxRelayContractsTest extends Unit
{
    private const ID = '01890f4d-3c2a-7f48-8c0b-123456789ac4';

    public function testAcceptsBoundedCommandAndSettings(): void
    {
        self::assertSame(1, (new RelayOutboxCommand(1))->limit);
        self::assertSame(100, (new RelayOutboxCommand(100))->limit);
        self::assertSame(60, (new OutboxRelaySettings(1, 60, 1, 1))->leaseSeconds);
        self::assertSame(3600, (new OutboxRelaySettings(10, 3600, 300, 3600))->retryMaxSeconds);
    }

    /** @dataProvider invalidLimits */
    public function testRejectsInvalidCommandLimit(int $limit): void
    {
        $this->expectException(OutboxRelayException::class);
        $this->expectExceptionMessage('configuration_invalid');
        new RelayOutboxCommand($limit);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidLimits(): iterable
    {
        yield 'negative' => [-1];
        yield 'zero' => [0];
        yield 'too large' => [101];
    }

    /** @dataProvider invalidSettings */
    public function testRejectsInvalidSettings(int $attempts, int $lease, int $base, int $cap): void
    {
        $this->expectException(OutboxRelayException::class);
        $this->expectExceptionMessage('configuration_invalid');
        new OutboxRelaySettings($attempts, $lease, $base, $cap);
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function invalidSettings(): iterable
    {
        yield 'no attempts' => [0, 600, 15, 900];
        yield 'too many attempts' => [11, 600, 15, 900];
        yield 'short lease' => [5, 59, 15, 900];
        yield 'long lease' => [5, 3601, 15, 900];
        yield 'zero base' => [5, 600, 0, 900];
        yield 'large base' => [5, 600, 301, 900];
        yield 'cap below base' => [5, 600, 15, 14];
        yield 'large cap' => [5, 600, 15, 3601];
    }

    public function testClaimPreservesMessageAndNormalizesLeaseToUtc(): void
    {
        $claim = self::claim();
        self::assertSame(self::ID, $claim->envelope?->outboxId);
        self::assertTrue($claim->attemptStarted);
        self::assertNull($claim->rejection);
        self::assertSame('2026-09-28T07:00:00+00:00', $claim->claimedAt->format('c'));
        self::assertSame('2026-09-28T07:10:00+00:00', $claim->leaseUntil->format('c'));

        $exhausted = self::claim(envelope: null, rejection: OutboxRelayError::ATTEMPT_LIMIT_REACHED, started: false);
        self::assertFalse($exhausted->attemptStarted);
        self::assertNull($exhausted->envelope);
    }

    /** @dataProvider invalidClaims */
    public function testRejectsContradictoryClaim(string $case): void
    {
        $this->expectException(OutboxRelayException::class);
        $this->expectExceptionMessage('invalid_message');
        match ($case) {
            'neither' => self::claim(envelope: null),
            'both' => self::claim(rejection: OutboxRelayError::INVALID_MESSAGE),
            'not started' => self::claim(started: false),
            'expired' => self::claim(until: '2026-09-28T12:00:00+05:00'),
            'zero attempt' => self::claim(attempt: 0),
            'invalid id' => self::claim(id: 'synthetic-invalid-id'),
            'mismatched message' => self::claim(id: '01890f4d-3c2a-7f48-8c0b-123456789ac5'),
            'blank token' => self::claim(token: ' '),
            'long token' => self::claim(token: str_repeat('x', 129)),
            'control token' => self::claim(token: "synthetic\n"),
            'operational rejection' => self::claim(envelope: null, rejection: OutboxRelayError::PERSISTENCE_FAILURE),
            default => self::fail('Unexpected claim test case.'),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function invalidClaims(): iterable
    {
        foreach ([
            'neither', 'both', 'not started', 'expired', 'zero attempt', 'invalid id',
            'mismatched message', 'blank token', 'long token', 'control token', 'operational rejection',
        ] as $case) {
            yield $case => [$case];
        }
    }

    public function testDecisionFactoriesPreventContradictoryOutcomes(): void
    {
        $delivered = OutboxRelayDecision::delivered();
        self::assertSame('DELIVERED', $delivered->status);
        self::assertNull($delivered->error);
        self::assertNull($delivered->delaySeconds);
        $retry = OutboxRelayDecision::retry(OutboxRelayError::CONFIRM_TIMEOUT, 18);
        self::assertSame('RETRY_SCHEDULED', $retry->status);
        self::assertSame(18, $retry->delaySeconds);
        $failed = OutboxRelayDecision::failed(OutboxRelayError::INVALID_MESSAGE);
        self::assertSame('FAILED', $failed->status);
        self::assertNull($failed->delaySeconds);
    }

    /** @dataProvider invalidDecisions */
    public function testRejectsInvalidDecision(string $case): void
    {
        $this->expectException(OutboxRelayException::class);
        match ($case) {
            'zero delay' => OutboxRelayDecision::retry(OutboxRelayError::NACKED, 0),
            'negative delay' => OutboxRelayDecision::retry(OutboxRelayError::NACKED, -1),
            'large delay' => OutboxRelayDecision::retry(OutboxRelayError::NACKED, 3601),
            'terminal retry' => OutboxRelayDecision::retry(OutboxRelayError::INVALID_MESSAGE, 15),
            'configuration failure' => OutboxRelayDecision::failed(OutboxRelayError::CONFIGURATION_INVALID),
            default => self::fail('Unexpected decision test case.'),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDecisions(): iterable
    {
        foreach (['zero delay', 'negative delay', 'large delay', 'terminal retry', 'configuration failure'] as $case) {
            yield $case => [$case];
        }
    }

    public function testReceiptAccountsForEveryClaim(): void
    {
        $receipt = new OutboxRelayReceipt(4, 1, 1, 1, 1);
        self::assertSame(4, $receipt->delivered + $receipt->retryScheduled + $receipt->failed + $receipt->leaseLost);
        self::assertSame(0, (new OutboxRelayReceipt(0, 0, 0, 0, 0))->claimed);
    }

    /** @dataProvider invalidReceipts */
    public function testRejectsInconsistentReceipt(int $claimed, int $delivered, int $retry, int $failed, int $lost): void
    {
        $this->expectException(OutboxRelayException::class);
        new OutboxRelayReceipt($claimed, $delivered, $retry, $failed, $lost);
    }

    /** @return iterable<string, array{int, int, int, int, int}> */
    public static function invalidReceipts(): iterable
    {
        yield 'unaccounted claim' => [1, 0, 0, 0, 0];
        yield 'negative outcome' => [0, -1, 1, 0, 0];
        yield 'exceeded run bound' => [101, 101, 0, 0, 0];
    }

    private static function claim(
        ?BrokerEnvelope $envelope = new BrokerEnvelope(
            self::ID,
            'telegram.update.received',
            '1.0',
            self::ID,
            new TelegramUpdateReceivedPayload(self::ID),
        ),
        ?OutboxRelayError $rejection = null,
        bool $started = true,
        string $until = '2026-09-28T12:10:00+05:00',
        int $attempt = 5,
        string $id = self::ID,
        string $token = 'synthetic-lease-token',
    ): OutboxRelayClaim {
        return new OutboxRelayClaim(
            $id,
            $token,
            $attempt,
            $started,
            new DateTimeImmutable('2026-09-28T12:00:00+05:00'),
            new DateTimeImmutable($until),
            $envelope,
            $rejection,
        );
    }
}
