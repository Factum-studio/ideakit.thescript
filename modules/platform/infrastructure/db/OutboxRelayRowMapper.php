<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\db;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\route\OutboxRouteRegistry;
use stdClass;

final class OutboxRelayRowMapper
{
    public function __construct(private readonly OutboxRouteRegistry $routes)
    {
    }

    /**
     * @param array<string, mixed> $row
     * @throws OutboxRelayException
     */
    public function claim(
        array $row,
        string $token,
        int $maxAttempts,
        DateTimeImmutable $claimedAt,
        DateTimeImmutable $leaseUntil,
    ): OutboxRelayClaim {
        $count = $row['attempt_count'] ?? null;
        if (is_string($count) && preg_match('/^(0|[1-9][0-9]{0,4})$/D', $count) === 1) {
            $count = (int) $count;
        }
        if (!is_int($count) || $count < 0 || $count > 32767 || !is_string($row['id'] ?? null)) {
            throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
        }
        $started = $count < $maxAttempts;
        $envelope = null;
        $rejection = null;
        if (!$started) {
            $rejection = OutboxRelayError::ATTEMPT_LIMIT_REACHED;
        } else {
            try {
                $envelope = $this->envelope($row);
                if (!is_string($row['attempt_history'] ?? null) || self::history($row['attempt_history'], $count) === null) {
                    throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
                }
            } catch (OutboxRelayException $exception) {
                $envelope = null;
                $rejection = $exception->error;
            }
        }

        return new OutboxRelayClaim(
            $row['id'],
            $token,
            $started ? $count + 1 : $count,
            $started,
            $claimedAt,
            $leaseUntil,
            $envelope,
            $rejection,
        );
    }

    /** @throws OutboxRelayException */
    public function completeHistory(
        string $json,
        OutboxRelayClaim $claim,
        OutboxRelayDecision $decision,
        DateTimeImmutable $finishedAt,
    ): string {
        $history = self::history($json, $claim->attemptNumber - (int) $claim->attemptStarted);
        if (!$claim->attemptStarted || $history === null) {
            return $json;
        }
        $history['attempts'][] = [
            'attempt_no' => $claim->attemptNumber,
            'started_at' => self::utc($claim->claimedAt),
            'finished_at' => self::utc($finishedAt),
            'outcome' => $decision->status,
            'error_code' => $decision->error?->value,
        ];

        return json_encode($history, JSON_THROW_ON_ERROR);
    }

    /** @throws OutboxRelayException */
    public function completeExpiredHistory(
        string $json,
        int $attemptCount,
        DateTimeImmutable $startedAt,
        OutboxRelayDecision $decision,
        DateTimeImmutable $finishedAt,
    ): ?string {
        if ($attemptCount < 1 || $attemptCount > 10 || $finishedAt < $startedAt) {
            throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
        }
        $history = self::history($json, $attemptCount);
        if ($history === null) {
            throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
        }
        $attempts = $history['attempts'];
        $lastAttempt = $attempts === [] ? 0 : $attempts[count($attempts) - 1]['attempt_no'];
        if ($lastAttempt === $attemptCount) {
            if ($decision->status !== 'FAILED' || !in_array($decision->error, [
                OutboxRelayError::LEASE_EXPIRED, OutboxRelayError::ATTEMPT_LIMIT_REACHED,
            ], true)) {
                throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
            }

            return null;
        }
        if ($lastAttempt !== $attemptCount - 1 || $decision->error !== OutboxRelayError::LEASE_EXPIRED
            || !in_array($decision->status, ['RETRY_SCHEDULED', 'FAILED'], true)
        ) {
            throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
        }
        $history['attempts'][] = [
            'attempt_no' => $attemptCount,
            'started_at' => self::utc($startedAt),
            'finished_at' => self::utc($finishedAt),
            'outcome' => $decision->status,
            'error_code' => OutboxRelayError::LEASE_EXPIRED->value,
        ];

        return json_encode($history, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $row
     * @throws OutboxRelayException
     */
    private function envelope(array $row): BrokerEnvelope
    {
        foreach (['owner_module', 'destination', 'routing_key', 'message_type', 'schema_version',
            'aggregate_type', 'aggregate_id', 'payload', 'payload_hash', 'idempotency_key', 'correlation_id',
        ] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
            }
        }
        foreach (['recipient_user_id', 'telegram_identity_profile_id', 'chat_id', 'idea_publication_id', 'idea_version_id'] as $field) {
            if (!array_key_exists($field, $row) || $row[$field] !== null) {
                throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
            }
        }
        $route = $this->routes->find($row['message_type'], $row['schema_version']);
        if ($route === null || $row['owner_module'] !== $route->ownerModule
            || $row['aggregate_type'] !== $route->aggregateType
        ) {
            throw new OutboxRelayException(OutboxRelayError::UNSUPPORTED_ROUTE);
        }
        if ($row['destination'] !== $route->destination || $row['routing_key'] !== $route->routingKey) {
            throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
        }
        if (strlen($row['payload']) > 4096) {
            throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
        }
        try {
            $payload = json_decode($row['payload'], false, 16, JSON_THROW_ON_ERROR);
            if (!$payload instanceof stdClass) {
                throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
            }
            $intent = new OutboxWriteIntent(
                $row['owner_module'],
                $row['message_type'],
                $row['schema_version'],
                $row['aggregate_type'],
                $row['aggregate_id'],
                $route->payloadCodec->decode(get_object_vars($payload)),
                $row['idempotency_key'],
                $row['correlation_id'],
            );
            $route = $this->routes->resolve($intent);
            $canonical = json_encode($intent->payload->technicalFields(), JSON_THROW_ON_ERROR);
            if (strlen($canonical) > $route->maximumPayloadBytes
                || !hash_equals(hash('sha256', $canonical), $row['payload_hash'])
            ) {
                throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
            }

            return new BrokerEnvelope(
                $row['id'],
                $intent->messageType,
                $intent->schemaVersion,
                $intent->correlationId,
                $intent->payload,
            );
        } catch (OutboxWriteException $exception) {
            throw new OutboxRelayException($exception->failure === OutboxWriteFailure::UNSUPPORTED_ROUTE
                ? OutboxRelayError::UNSUPPORTED_ROUTE : OutboxRelayError::INVALID_MESSAGE);
        } catch (JsonException | BrokerTransportException) {
            throw new OutboxRelayException(OutboxRelayError::INVALID_MESSAGE);
        }
    }

    /** @return array{schema_version: string, attempts: list<array<string, mixed>>}|null */
    private static function history(string $json, int $attemptCount): ?array
    {
        if (strlen($json) > 16384) {
            return null;
        }
        try {
            $history = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!$history instanceof stdClass || !self::hasKeys(get_object_vars($history), ['schema_version', 'attempts'])
            || $history->schema_version !== '1.0' || !is_array($history->attempts)
            || count($history->attempts) > min($attemptCount, 10)
        ) {
            return null;
        }
        $entries = [];
        $previousAttempt = 0;
        foreach ($history->attempts as $entry) {
            if (!$entry instanceof stdClass || !self::hasKeys(get_object_vars($entry), [
                'attempt_no', 'started_at', 'finished_at', 'outcome', 'error_code',
            ]) || !is_int($entry->attempt_no) || $entry->attempt_no <= $previousAttempt
                || $entry->attempt_no > $attemptCount || !is_string($entry->started_at)
                || !is_string($entry->finished_at) || !is_string($entry->outcome)
            ) {
                return null;
            }
            $started = self::historyTime($entry->started_at);
            $finished = self::historyTime($entry->finished_at);
            if ($started === null || $finished === null || $finished < $started) {
                return null;
            }
            $error = is_string($entry->error_code) ? OutboxRelayError::tryFrom($entry->error_code) : null;
            if (($entry->outcome === 'DELIVERED' && $entry->error_code !== null)
                || !in_array($entry->outcome, ['DELIVERED', 'RETRY_SCHEDULED', 'FAILED'], true)
            ) {
                return null;
            }
            try {
                if ($entry->outcome === 'RETRY_SCHEDULED') {
                    if ($error === null) {
                        return null;
                    }
                    OutboxRelayDecision::retry($error, 1);
                } elseif ($entry->outcome === 'FAILED') {
                    if ($error === null) {
                        return null;
                    }
                    OutboxRelayDecision::failed($error);
                }
            } catch (OutboxRelayException) {
                return null;
            }
            $entries[] = get_object_vars($entry);
            $previousAttempt = $entry->attempt_no;
        }

        return ['schema_version' => '1.0', 'attempts' => $entries];
    }

    /** @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function hasKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private static function historyTime(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|\+00:00)$/D', $value) !== 1) {
            return null;
        }
        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $time = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $time !== false && ($errors === false || $errors['warning_count'] + $errors['error_count'] === 0)
            ? $time : null;
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
