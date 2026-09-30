<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure;

use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\route\OutboxRouteRegistry;
use modules\platform\infrastructure\db\OutboxRelayRowMapper;

final class OutboxRelayRowMapperTest extends Unit
{
    private const ID = '01890f4d-3c2a-7f48-8c0b-123456789cc1';
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789cc2';

    public function testRestoresEnvelopeUsingWriterHashRatherThanJsonbText(): void
    {
        $claim = $this->claim(self::row());
        self::assertNull($claim->rejection);
        self::assertNotNull($claim->envelope);
        self::assertSame(self::ID, $claim->envelope->outboxId);
        self::assertSame(['update_id' => self::UPDATE_ID], $claim->envelope->payload->technicalFields());
        self::assertSame(1, $claim->attemptNumber);
        self::assertTrue($claim->attemptStarted);
    }

    /**
     * @dataProvider invalidRows
     * @param array<string, mixed> $changes
     */
    public function testRejectsInvalidPersistedContract(array $changes, OutboxRelayError $error): void
    {
        $claim = $this->claim(array_replace(self::row(), $changes));
        self::assertNull($claim->envelope);
        self::assertSame($error, $claim->rejection);
    }

    /** @return iterable<string, array{array<string, mixed>, OutboxRelayError}> */
    public static function invalidRows(): iterable
    {
        foreach ([
            'missing payload' => ['payload' => null],
            'invalid json' => ['payload' => '{'],
            'extra payload field' => ['payload' => '{"update_id":"' . self::UPDATE_ID . '","extra":true}'],
            'wrong payload type' => ['payload' => '{"update_id":42}'],
            'mismatched aggregate' => ['aggregate_id' => self::ID],
            'uppercase correlation' => ['correlation_id' => strtoupper(self::ID)],
            'wrong hash' => ['payload_hash' => str_repeat('a', 64)],
            'wrong destination' => ['destination' => 'TELEGRAM'],
            'wrong routing' => ['routing_key' => 'different'],
            'bad history json' => ['attempt_history' => '{'],
            'unknown history schema' => ['attempt_history' => '{"schema_version":"2.0","attempts":[]}'],
            'unknown history field' => ['attempt_history' => '{"schema_version":"1.0","attempts":[],"extra":true}'],
            'inconsistent history count' => ['attempt_history' => json_encode(self::history(), JSON_THROW_ON_ERROR)],
        ] as $case => $changes) {
            yield $case => [$changes, OutboxRelayError::INVALID_MESSAGE];
        }
        foreach (['recipient_user_id', 'telegram_identity_profile_id', 'chat_id', 'idea_publication_id', 'idea_version_id'] as $field) {
            yield $field => [[$field => self::ID], OutboxRelayError::INVALID_MESSAGE];
        }
        foreach (['owner_module', 'message_type', 'schema_version', 'aggregate_type'] as $field) {
            yield $field => [[$field => 'unsupported'], OutboxRelayError::UNSUPPORTED_ROUTE];
        }
    }

    public function testExhaustedBudgetDoesNotStartAnotherAttempt(): void
    {
        $claim = $this->claim(array_replace(self::row(), ['attempt_count' => 5]));
        self::assertSame(5, $claim->attemptNumber);
        self::assertFalse($claim->attemptStarted);
        self::assertSame(OutboxRelayError::ATTEMPT_LIMIT_REACHED, $claim->rejection);
    }

    public function testAppendsOneCompletedAttemptWithoutRewritingEarlierEntries(): void
    {
        $mapper = new OutboxRelayRowMapper(new OutboxRouteRegistry());
        $row = array_replace(self::row(), [
            'attempt_count' => 2,
            'attempt_history' => json_encode(self::history(), JSON_THROW_ON_ERROR),
        ]);
        $claim = $this->claim($row);
        $history = json_decode($mapper->completeHistory(
            $row['attempt_history'],
            $claim,
            OutboxRelayDecision::retry(OutboxRelayError::CONFIRM_TIMEOUT, 15),
            new DateTimeImmutable('2026-09-28T12:00:01+05:00'),
        ), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(self::history()['attempts'][0], $history['attempts'][0]);
        self::assertSame([
            'attempt_no' => 3,
            'started_at' => '2026-09-28T07:00:00.000000Z',
            'finished_at' => '2026-09-28T07:00:01.000000Z',
            'outcome' => 'RETRY_SCHEDULED',
            'error_code' => 'confirm_timeout',
        ], $history['attempts'][1]);
    }

    /** @dataProvider expiredLeaseDecisions */
    public function testClosesInterruptedAttemptWithoutRewritingHistory(OutboxRelayDecision $decision): void
    {
        $history = self::history();
        $completed = (new OutboxRelayRowMapper(new OutboxRouteRegistry()))->completeExpiredHistory(
            json_encode($history, JSON_THROW_ON_ERROR),
            2,
            new DateTimeImmutable('2026-09-28T12:00:00+05:00'),
            $decision,
            new DateTimeImmutable('2026-09-28T12:00:01+05:00'),
        );

        self::assertNotNull($completed);
        $result = json_decode($completed, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($history['attempts'][0], $result['attempts'][0]);
        self::assertSame([
            'attempt_no' => 2,
            'started_at' => '2026-09-28T07:00:00.000000Z',
            'finished_at' => '2026-09-28T07:00:01.000000Z',
            'outcome' => $decision->status,
            'error_code' => 'lease_expired',
        ], $result['attempts'][1]);
    }

    /** @return iterable<string, array{OutboxRelayDecision}> */
    public static function expiredLeaseDecisions(): iterable
    {
        yield 'retry' => [OutboxRelayDecision::retry(OutboxRelayError::LEASE_EXPIRED, 15)];
        yield 'exhausted' => [OutboxRelayDecision::failed(OutboxRelayError::LEASE_EXPIRED)];
    }

    public function testClosesFirstInterruptedAttemptFromEmptyHistory(): void
    {
        $completed = (new OutboxRelayRowMapper(new OutboxRouteRegistry()))->completeExpiredHistory(
            '{"schema_version":"1.0","attempts":[]}',
            1,
            new DateTimeImmutable('2026-09-28T07:00:00Z'),
            OutboxRelayDecision::retry(OutboxRelayError::LEASE_EXPIRED, 15),
            new DateTimeImmutable('2026-09-28T07:00:01Z'),
        );

        self::assertNotNull($completed);
        $history = json_decode($completed, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(1, $history['attempts'][0]['attempt_no']);
        self::assertCount(1, $history['attempts']);
    }

    public function testDoesNotAppendAttemptWhenBudgetWasAlreadyExhausted(): void
    {
        $json = json_encode(self::history(), JSON_THROW_ON_ERROR);

        self::assertNull((new OutboxRelayRowMapper(new OutboxRouteRegistry()))->completeExpiredHistory(
            $json,
            1,
            new DateTimeImmutable('2026-09-28T12:00:00+05:00'),
            OutboxRelayDecision::failed(OutboxRelayError::LEASE_EXPIRED),
            new DateTimeImmutable('2026-09-28T12:00:01+05:00'),
        ));
    }

    public function testRejectsCompletedHistoryWhenRetryBudgetRemains(): void
    {
        $this->expectException(OutboxRelayException::class);
        $this->expectExceptionMessage('invalid_message');

        (new OutboxRelayRowMapper(new OutboxRouteRegistry()))->completeExpiredHistory(
            json_encode(self::history(), JSON_THROW_ON_ERROR),
            1,
            new DateTimeImmutable('2026-09-28T12:00:00+05:00'),
            OutboxRelayDecision::retry(OutboxRelayError::LEASE_EXPIRED, 15),
            new DateTimeImmutable('2026-09-28T12:00:01+05:00'),
        );
    }

    /** @dataProvider invalidExpiredHistories */
    public function testRejectsInconsistentInterruptedAttempt(string $json, int $attemptCount): void
    {
        $this->expectException(OutboxRelayException::class);
        $this->expectExceptionMessage('invalid_message');

        (new OutboxRelayRowMapper(new OutboxRouteRegistry()))->completeExpiredHistory(
            $json,
            $attemptCount,
            new DateTimeImmutable('2026-09-28T12:00:00+05:00'),
            OutboxRelayDecision::retry(OutboxRelayError::LEASE_EXPIRED, 15),
            new DateTimeImmutable('2026-09-28T12:00:01+05:00'),
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidExpiredHistories(): iterable
    {
        yield 'malformed' => ['{', 2];
        yield 'missing previous attempt' => ['{"schema_version":"1.0","attempts":[]}', 2];
        yield 'skipped previous attempt' => [json_encode(self::history(), JSON_THROW_ON_ERROR), 3];
    }

    /** @dataProvider invalidHistories */
    public function testPreservesUnusableHistoryWhenFinalizingRejection(string $json): void
    {
        $row = array_replace(self::row(), ['attempt_count' => 1, 'attempt_history' => $json]);
        $claim = $this->claim($row);
        self::assertSame(OutboxRelayError::INVALID_MESSAGE, $claim->rejection);
        self::assertSame($json, (new OutboxRelayRowMapper(new OutboxRouteRegistry()))->completeHistory(
            $json,
            $claim,
            OutboxRelayDecision::failed(OutboxRelayError::INVALID_MESSAGE),
            new DateTimeImmutable('2026-09-28T07:00:01Z'),
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHistories(): iterable
    {
        $entry = self::history()['attempts'][0];
        yield 'unknown schema' => ['{"schema_version":"2.0","attempts":[]}'];
        foreach ([
            'unknown entry field' => array_merge($entry, ['extra' => true]),
            'string attempt' => array_replace($entry, ['attempt_no' => '1']),
            'unknown outcome' => array_replace($entry, ['outcome' => 'UNKNOWN']),
            'missing error' => array_replace($entry, ['error_code' => null]),
            'unsafe error' => array_replace($entry, ['error_code' => 'synthetic diagnostic']),
            'reversed time' => array_replace($entry, ['finished_at' => '2026-09-28T05:59:00.000000Z']),
            'non utc time' => array_replace($entry, ['started_at' => '2026-09-28T07:00:00+01:00']),
            'impossible date' => array_replace($entry, ['started_at' => '2026-02-30T06:00:00.000000Z']),
        ] as $case => $changed) {
            yield $case => [json_encode(['schema_version' => '1.0', 'attempts' => [$changed]], JSON_THROW_ON_ERROR)];
        }
    }

    /** @param array<string, mixed> $row */
    private function claim(array $row): OutboxRelayClaim
    {
        return (new OutboxRelayRowMapper(new OutboxRouteRegistry()))->claim(
            $row,
            'synthetic-token',
            5,
            new DateTimeImmutable('2026-09-28T12:00:00+05:00'),
            new DateTimeImmutable('2026-09-28T12:10:00+05:00'),
        );
    }

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return [
            'id' => self::ID, 'owner_module' => 'Telegram', 'destination' => 'RABBITMQ',
            'routing_key' => 'critical', 'message_type' => 'telegram.update.received',
            'schema_version' => '1.0', 'aggregate_type' => 'TELEGRAM_UPDATE', 'aggregate_id' => self::UPDATE_ID,
            'recipient_user_id' => null, 'telegram_identity_profile_id' => null, 'chat_id' => null,
            'idea_publication_id' => null, 'idea_version_id' => null,
            'payload' => '{"update_id": "' . self::UPDATE_ID . '"}',
            'payload_hash' => hash('sha256', json_encode(['update_id' => self::UPDATE_ID], JSON_THROW_ON_ERROR)),
            'idempotency_key' => 'synthetic-relay-intent', 'correlation_id' => self::ID,
            'attempt_count' => 0, 'attempt_history' => '{"schema_version": "1.0", "attempts": []}',
        ];
    }

    /** @return array{schema_version: string, attempts: list<array<string, int|string|null>>} */
    private static function history(): array
    {
        return ['schema_version' => '1.0', 'attempts' => [[
            'attempt_no' => 1, 'started_at' => '2026-09-28T06:00:00.000000Z',
            'finished_at' => '2026-09-28T06:00:01.000000Z',
            'outcome' => 'RETRY_SCHEDULED', 'error_code' => 'confirm_timeout',
        ]]];
    }
}
