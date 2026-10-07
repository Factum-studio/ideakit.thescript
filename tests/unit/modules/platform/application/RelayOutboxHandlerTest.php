<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\application;

use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\platform\application\command\RelayOutboxCommand;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\BrokerPublishReceipt;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelayReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\handler\RelayOutboxHandler;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IBrokerPublisher;
use modules\platform\application\port\IOutboxRelayStore;
use modules\platform\application\port\IRetryJitter;
use modules\platform\infrastructure\random\SecureRetryJitter;
use RuntimeException;
use Throwable;

final class RelayOutboxHandlerTest extends Unit
{
    public function testCommitsConfirmedPublicationAfterCheckingLease(): void
    {
        $claim = self::claim();
        $events = [];
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturnCallback(
            static function () use ($claim, &$events): OutboxRelayClaim {
                $events[] = 'claim';

                return $claim;
            },
        );
        $store->expects(self::once())->method('isLeaseActive')->with($claim)->willReturnCallback(
            static function () use (&$events): bool {
                $events[] = 'lease';

                return true;
            },
        );
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')->with($claim->envelope)->willReturnCallback(
            static function (BrokerEnvelope $envelope) use (&$events): BrokerPublishReceipt {
                $events[] = 'publish';

                return new BrokerPublishReceipt($envelope->outboxId);
            },
        );
        $store->expects(self::once())->method('finish')->with($claim, self::callback(
            static function (OutboxRelayDecision $decision): bool {
                self::assertSame('DELIVERED', $decision->status);
                self::assertNull($decision->error);

                return true;
            },
        ))->willReturnCallback(static function () use (&$events): bool {
            $events[] = 'finish';

            return true;
        });

        $receipt = self::handler($store, $publisher)->handle(new RelayOutboxCommand(1));

        self::assertSame(['claim', 'lease', 'publish', 'finish'], $events);
        self::assertSame([1, 1, 0, 0, 0], [
            $receipt->claimed, $receipt->delivered, $receipt->retryScheduled, $receipt->failed, $receipt->leaseLost,
        ]);
    }

    public function testEmptyOutboxStopsWithoutPublishing(): void
    {
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturn(null);
        $store->expects(self::never())->method('isLeaseActive');
        $store->expects(self::never())->method('finish');
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::never())->method('publish');

        self::assertEquals(
            new OutboxRelayReceipt(0, 0, 0, 0, 0),
            self::handler($store, $publisher)->handle(new RelayOutboxCommand(100)),
        );
    }

    public function testRejectedAndLostClaimsConsumeRunLimit(): void
    {
        $rejected = self::claim(rejection: OutboxRelayError::INVALID_MESSAGE);
        $lost = self::claim();
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::exactly(2))->method('claimNext')->willReturnOnConsecutiveCalls($rejected, $lost);
        $store->expects(self::once())->method('isLeaseActive')->with($lost)->willReturn(false);
        $store->expects(self::once())->method('finish')->with($rejected, self::callback(
            static fn (OutboxRelayDecision $decision): bool => $decision->status === 'FAILED'
                && $decision->error === OutboxRelayError::INVALID_MESSAGE,
        ))->willReturn(true);
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::never())->method('publish');

        self::assertEquals(
            new OutboxRelayReceipt(2, 0, 0, 1, 1),
            self::handler($store, $publisher)->handle(new RelayOutboxCommand(2)),
        );
    }

    /** @dataProvider publishFailures */
    public function testTransportFailureIsFinalizedOnce(
        BrokerTransportErrorCode $code,
        int $attempt,
        string $status,
        OutboxRelayError $error,
        ?int $delay,
    ): void {
        $claim = self::claim($attempt);
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturn($claim);
        $store->expects(self::once())->method('isLeaseActive')->with($claim)->willReturn(true);
        $store->expects(self::once())->method('finish')->with($claim, self::callback(
            static function (OutboxRelayDecision $decision) use ($status, $error, $delay): bool {
                self::assertSame($status, $decision->status);
                self::assertSame($error, $decision->error);
                self::assertSame($delay, $decision->delaySeconds);

                return true;
            },
        ))->willReturn(true);
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')->with($claim->envelope)
            ->willThrowException(new BrokerTransportException($code));

        self::assertEquals(
            new OutboxRelayReceipt(1, 0, $status === 'RETRY_SCHEDULED' ? 1 : 0, $status === 'FAILED' ? 1 : 0, 0),
            self::handler($store, $publisher)->handle(new RelayOutboxCommand(1)),
        );
    }

    /** @return iterable<string, array{BrokerTransportErrorCode, int, string, OutboxRelayError, ?int}> */
    public static function publishFailures(): iterable
    {
        yield 'connection retry' => [
            BrokerTransportErrorCode::CONNECTION_FAILURE, 1, 'RETRY_SCHEDULED', OutboxRelayError::CONNECTION_FAILURE, 15,
        ];
        yield 'nack retry' => [BrokerTransportErrorCode::NACKED, 1, 'RETRY_SCHEDULED', OutboxRelayError::NACKED, 15];
        yield 'ambiguous confirm retry' => [
            BrokerTransportErrorCode::CONFIRM_TIMEOUT, 1, 'RETRY_SCHEDULED', OutboxRelayError::CONFIRM_TIMEOUT, 15,
        ];
        yield 'last attempt' => [BrokerTransportErrorCode::CONFIRM_TIMEOUT, 5, 'FAILED', OutboxRelayError::CONFIRM_TIMEOUT, null];
        yield 'unroutable' => [BrokerTransportErrorCode::UNROUTABLE, 1, 'FAILED', OutboxRelayError::UNROUTABLE, null];
        yield 'invalid envelope' => [BrokerTransportErrorCode::INVALID_ENVELOPE, 1, 'FAILED', OutboxRelayError::INVALID_MESSAGE, null];
    }

    /** @dataProvider rejectedClaims */
    public function testRejectedClaimIsFinalizedWithoutPublisher(OutboxRelayError $error): void
    {
        $claim = self::claim($error === OutboxRelayError::ATTEMPT_LIMIT_REACHED ? 5 : 1, $error);
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturn($claim);
        $store->expects(self::never())->method('isLeaseActive');
        $store->expects(self::once())->method('finish')->with($claim, self::callback(
            static fn (OutboxRelayDecision $decision): bool => $decision->status === 'FAILED'
                && $decision->error === $error && $decision->delaySeconds === null,
        ))->willReturn(true);
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::never())->method('publish');

        self::assertEquals(
            new OutboxRelayReceipt(1, 0, 0, 1, 0),
            self::handler($store, $publisher)->handle(new RelayOutboxCommand(1)),
        );
    }

    /** @return iterable<string, array{OutboxRelayError}> */
    public static function rejectedClaims(): iterable
    {
        foreach ([
            OutboxRelayError::INVALID_MESSAGE, OutboxRelayError::UNSUPPORTED_ROUTE, OutboxRelayError::ATTEMPT_LIMIT_REACHED,
        ] as $error) {
            yield $error->value => [$error];
        }
    }

    /** @dataProvider lostLeases */
    public function testLostLeaseNeverCountsAsCommittedResult(string $stage): void
    {
        $claim = self::claim(rejection: $stage === 'rejected' ? OutboxRelayError::INVALID_MESSAGE : null);
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturn($claim);
        $store->expects($stage === 'rejected' ? self::never() : self::once())->method('isLeaseActive')
            ->with($claim)->willReturn($stage !== 'before publish');
        $store->expects($stage === 'before publish' ? self::never() : self::once())->method('finish')
            ->willReturn(false);
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publishCall = $publisher->expects(in_array($stage, ['before publish', 'rejected'], true) ? self::never() : self::once())
            ->method('publish')->with($claim->envelope);
        if ($stage === 'retry') {
            $publishCall->willThrowException(new BrokerTransportException(BrokerTransportErrorCode::NACKED));
        } elseif ($stage === 'delivered') {
            $publishCall->willReturn(new BrokerPublishReceipt($claim->outboxId));
        }

        self::assertEquals(
            new OutboxRelayReceipt(1, 0, 0, 0, 1),
            self::handler($store, $publisher)->handle(new RelayOutboxCommand(1)),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function lostLeases(): iterable
    {
        foreach (['before publish', 'delivered', 'retry', 'rejected'] as $stage) {
            yield $stage => [$stage];
        }
    }

    public function testMismatchedConfirmIsNotDelivery(): void
    {
        $claim = self::claim();
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturn($claim);
        $store->expects(self::once())->method('isLeaseActive')->with($claim)->willReturn(true);
        $store->expects(self::once())->method('finish')->with($claim, self::callback(
            static fn (OutboxRelayDecision $decision): bool => $decision->status === 'FAILED'
                && $decision->error === OutboxRelayError::CONFIRM_MISMATCH,
        ))->willReturn(true);
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')->with($claim->envelope)
            ->willReturn(new BrokerPublishReceipt('01900000-0000-7000-8000-000000000004'));

        self::assertEquals(
            new OutboxRelayReceipt(1, 0, 0, 1, 0),
            self::handler($store, $publisher)->handle(new RelayOutboxCommand(1)),
        );
    }

    public function testExplicitRetryAfterLostConfirmPreservesOriginalMessage(): void
    {
        $first = self::claim();
        $second = self::claim(2, token: 'lease-two');
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::exactly(2))->method('claimNext')->willReturnOnConsecutiveCalls($first, $second);
        $store->expects(self::exactly(2))->method('isLeaseActive')->willReturn(true);
        $decisions = [];
        $store->expects(self::exactly(2))->method('finish')->willReturnCallback(
            static function (OutboxRelayClaim $claim, OutboxRelayDecision $decision) use (&$decisions): bool {
                $decisions[] = [$claim->outboxId, $decision->status];

                return true;
            },
        );
        $sent = [];
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function (BrokerEnvelope $envelope) use (&$sent): BrokerPublishReceipt {
                $sent[] = $envelope;
                if (count($sent) === 1) {
                    throw new BrokerTransportException(BrokerTransportErrorCode::CONFIRM_TIMEOUT);
                }

                return new BrokerPublishReceipt($envelope->outboxId);
            },
        );
        $handler = self::handler($store, $publisher);

        self::assertEquals(new OutboxRelayReceipt(1, 0, 1, 0, 0), $handler->handle(new RelayOutboxCommand(1)));
        self::assertEquals(new OutboxRelayReceipt(1, 1, 0, 0, 0), $handler->handle(new RelayOutboxCommand(1)));
        self::assertSame([$first->envelope, $second->envelope], $sent);
        self::assertEquals($sent[0], $sent[1]);
        self::assertSame([
            [$first->outboxId, 'RETRY_SCHEDULED'], [$first->outboxId, 'DELIVERED'],
        ], $decisions);
    }

    /** @dataProvider operationalFailures */
    public function testOperationalFailureStopsBeforeNextClaim(
        string $stage,
        Throwable $failure,
        OutboxRelayError $expected,
    ): void {
        $claim = self::claim();
        $store = $this->createMock(IOutboxRelayStore::class);
        $claimCall = $store->expects(self::once())->method('claimNext');
        if ($stage === 'claim') {
            $claimCall->willThrowException($failure);
        } else {
            $claimCall->willReturn($claim);
        }
        $leaseCall = $store->expects($stage === 'claim' ? self::never() : self::once())->method('isLeaseActive');
        if ($stage === 'lease') {
            $leaseCall->willThrowException($failure);
        } else {
            $leaseCall->willReturn(true);
        }
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publishCall = $publisher->expects(in_array($stage, ['claim', 'lease'], true) ? self::never() : self::once())
            ->method('publish');
        if ($stage === 'publish') {
            $publishCall->willThrowException($failure);
        } else {
            $publishCall->willReturn(new BrokerPublishReceipt($claim->outboxId));
        }
        $store->expects($stage === 'finish' ? self::once() : self::never())->method('finish')->willThrowException($failure);

        try {
            self::handler($store, $publisher)->handle(new RelayOutboxCommand(100));
            self::fail('Expected relay to stop.');
        } catch (OutboxRelayException $exception) {
            self::assertSame($expected, $exception->error);
            self::assertSame($expected->value, $exception->getMessage());
            if ($failure instanceof OutboxRelayException) {
                self::assertSame($failure, $exception);
            }
            self::assertSame(
                $failure instanceof OutboxRelayException || $failure instanceof BrokerTransportException ? null : $failure,
                $exception->getPrevious(),
            );
            if ($failure instanceof RuntimeException && !$failure instanceof OutboxRelayException
                && !$failure instanceof BrokerTransportException
            ) {
                self::assertSame(
                    $stage === 'publish' ? SafeCauseCode::UNKNOWN : SafeCauseCode::PERSISTENCE,
                    $exception->causeCode,
                );
            }
        }
    }

    /** @return iterable<string, array{string, Throwable, OutboxRelayError}> */
    public static function operationalFailures(): iterable
    {
        yield 'claim failure' => ['claim', new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE), OutboxRelayError::PERSISTENCE_FAILURE];
        yield 'caller transaction' => ['claim', new OutboxRelayException(OutboxRelayError::TRANSACTION_ALREADY_ACTIVE), OutboxRelayError::TRANSACTION_ALREADY_ACTIVE];
        yield 'finish after acceptance' => ['finish', new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE), OutboxRelayError::PERSISTENCE_FAILURE];
        foreach (['claim', 'lease', 'publish', 'finish'] as $stage) {
            yield $stage . ' unexpected failure' => [
                $stage,
                new RuntimeException('synthetic-sensitive-detail', 0, new RuntimeException('synthetic-inner-detail')),
                OutboxRelayError::UNEXPECTED_FAILURE,
            ];
        }
        foreach ([
            [BrokerTransportErrorCode::CONFIGURATION_INVALID, OutboxRelayError::CONFIGURATION_INVALID],
            [BrokerTransportErrorCode::TOPOLOGY_MISMATCH, OutboxRelayError::TOPOLOGY_MISMATCH],
            [BrokerTransportErrorCode::DELIVERY_ALREADY_SETTLED, OutboxRelayError::UNEXPECTED_FAILURE],
            [BrokerTransportErrorCode::DELIVERY_UNAVAILABLE, OutboxRelayError::UNEXPECTED_FAILURE],
        ] as [$code, $expected]) {
            yield $code->value => ['publish', new BrokerTransportException($code), $expected];
        }
    }

    public function testJitterFailureStopsWithoutSchedulingOrRepublishing(): void
    {
        $claim = self::claim();
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturn($claim);
        $store->expects(self::once())->method('isLeaseActive')->willReturn(true);
        $store->expects(self::never())->method('finish');
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')
            ->willThrowException(new BrokerTransportException(BrokerTransportErrorCode::NACKED));
        $jitter = new class () implements IRetryJitter {
            public function between(int $minimum, int $maximum): int
            {
                throw new RuntimeException('synthetic-randomness-detail');
            }
        };

        try {
            self::handler($store, $publisher, jitter: $jitter)->handle(new RelayOutboxCommand(100));
            self::fail('Expected relay to stop.');
        } catch (OutboxRelayException $exception) {
            self::assertSame(OutboxRelayError::UNEXPECTED_FAILURE, $exception->error);
            self::assertSame('unexpected_failure', $exception->getMessage());
            self::assertSame('synthetic-randomness-detail', $exception->getPrevious()?->getMessage());
        }
    }

    public function testSecureJitterUsesPolicyDelayCap(): void
    {
        $claim = self::claim();
        $store = $this->createMock(IOutboxRelayStore::class);
        $store->expects(self::once())->method('claimNext')->willReturn($claim);
        $store->expects(self::once())->method('isLeaseActive')->willReturn(true);
        $store->expects(self::once())->method('finish')->with($claim, self::callback(
            static fn (OutboxRelayDecision $decision): bool => $decision->status === 'RETRY_SCHEDULED'
                && $decision->delaySeconds === 15,
        ))->willReturn(true);
        $publisher = $this->createMock(IBrokerPublisher::class);
        $publisher->expects(self::once())->method('publish')
            ->willThrowException(new BrokerTransportException(BrokerTransportErrorCode::NACKED));

        self::assertEquals(
            new OutboxRelayReceipt(1, 0, 1, 0, 0),
            self::handler($store, $publisher, new OutboxRelaySettings(5, 600, 15, 15), new SecureRetryJitter())
                ->handle(new RelayOutboxCommand(1)),
        );
    }

    private static function claim(
        int $attempt = 1,
        ?OutboxRelayError $rejection = null,
        string $token = 'lease-one',
    ): OutboxRelayClaim {
        $id = '01900000-0000-7000-8000-000000000001';
        $envelope = new BrokerEnvelope(
            $id,
            'telegram.update.received',
            '1.0',
            '01900000-0000-7000-8000-000000000002',
            new TelegramUpdateReceivedPayload('01900000-0000-7000-8000-000000000003'),
        );

        return new OutboxRelayClaim(
            $id,
            $token,
            $attempt,
            $rejection !== OutboxRelayError::ATTEMPT_LIMIT_REACHED,
            new DateTimeImmutable('2026-09-29T10:00:00Z'),
            new DateTimeImmutable('2026-09-29T10:10:00Z'),
            $rejection === null ? $envelope : null,
            $rejection,
        );
    }

    private static function handler(
        IOutboxRelayStore $store,
        IBrokerPublisher $publisher,
        ?OutboxRelaySettings $settings = null,
        ?IRetryJitter $jitter = null,
    ): RelayOutboxHandler {
        $settings ??= new OutboxRelaySettings(5, 600, 15, 900);
        $jitter ??= new class () implements IRetryJitter {
            public function between(int $minimum, int $maximum): int
            {
                return $minimum;
            }
        };

        return new RelayOutboxHandler($store, $publisher, $settings, new OutboxRetryPolicy($settings, $jitter));
    }
}
