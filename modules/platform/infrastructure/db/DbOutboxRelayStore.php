<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\db;

use DateTimeImmutable;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\port\IOutboxLeaseTokenGenerator;
use modules\platform\application\port\IOutboxRelayStore;
use Throwable;
use yii\db\Connection;
use yii\db\Transaction;

final class DbOutboxRelayStore implements IOutboxRelayStore
{
    public function __construct(
        private readonly Connection $db,
        private readonly OutboxRelayRowMapper $mapper,
        private readonly IOutboxLeaseTokenGenerator $tokens,
    ) {
    }

    /** @throws OutboxRelayException */
    public function claimNext(OutboxRelaySettings $settings): ?OutboxRelayClaim
    {
        $transaction = null;
        try {
            $this->requireNoCallerTransaction();
            $transaction = $this->db->beginTransaction();
            $row = $this->db->createCommand(
                <<<'SQL'
SELECT * FROM {{%outbox_messages}}
WHERE destination = :destination
  AND (status = :pending OR (status = :retry AND next_attempt_at <= clock_timestamp()))
ORDER BY created_at ASC, id ASC
LIMIT 1 FOR UPDATE SKIP LOCKED
SQL,
                [':destination' => 'RABBITMQ', ':pending' => 'PENDING', ':retry' => 'RETRY_SCHEDULED'],
            )->queryOne();
            if ($row === false) {
                $transaction->commit();

                return null;
            }
            $instant = $this->db->createCommand(
                <<<'SQL'
WITH instant AS MATERIALIZED (SELECT clock_timestamp() AS now)
SELECT now, now + CAST(:lease AS integer) * INTERVAL '1 second' AS lease_until FROM instant
SQL,
                [':lease' => $settings->leaseSeconds],
            )->queryOne();
            if (!is_array($instant)) {
                throw new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE);
            }
            $claim = $this->mapper->claim(
                $row,
                $this->tokens->generate(),
                $settings->maxAttempts,
                new DateTimeImmutable($instant['now']),
                new DateTimeImmutable($instant['lease_until']),
            );
            $updated = $this->db->createCommand(
                <<<'SQL'
UPDATE {{%outbox_messages}}
SET status = :processing, locked_by = :token, locked_until = CAST(:until AS timestamptz),
    next_attempt_at = NULL, attempt_count = :attempt, updated_at = CAST(:now AS timestamptz)
WHERE id = :id
SQL,
                [
                    ':processing' => 'PROCESSING', ':token' => $claim->leaseToken,
                    ':until' => self::timestamp($claim->leaseUntil), ':attempt' => $claim->attemptNumber,
                    ':now' => self::timestamp($claim->claimedAt), ':id' => $claim->outboxId,
                ],
            )->execute();
            if ($updated !== 1) {
                throw new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE);
            }
            $transaction->commit();

            return $claim;
        } catch (OutboxRelayException $exception) {
            $this->rollBack($transaction);
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack($transaction);
            throw new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE, $exception, SafeCauseCode::PERSISTENCE);
        }
    }

    /** @throws OutboxRelayException */
    public function isLeaseActive(OutboxRelayClaim $claim): bool
    {
        try {
            $this->requireNoCallerTransaction();

            return $this->leaseMatches($claim);
        } catch (OutboxRelayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE, $exception, SafeCauseCode::PERSISTENCE);
        }
    }

    /** @throws OutboxRelayException */
    public function finish(OutboxRelayClaim $claim, OutboxRelayDecision $decision): bool
    {
        $transaction = null;
        try {
            $this->requireNoCallerTransaction();
            $transaction = $this->db->beginTransaction();
            $row = $this->db->createCommand(
                'SELECT attempt_history FROM {{%outbox_messages}} WHERE id = :id FOR UPDATE',
                [':id' => $claim->outboxId],
            )->queryOne();
            if ($row === false || !$this->leaseMatches($claim)) {
                $transaction->commit();

                return false;
            }
            $now = new DateTimeImmutable($this->db->createCommand('SELECT clock_timestamp()')->queryScalar());
            $history = $this->mapper->completeHistory($row['attempt_history'], $claim, $decision, $now);
            $updated = $this->db->createCommand(
                <<<'SQL'
UPDATE {{%outbox_messages}}
SET status = CAST(:status AS varchar(24)), updated_at = CAST(:now AS timestamptz),
    attempt_history = CAST(:history AS jsonb), last_error_code = :error,
    locked_by = NULL, locked_until = NULL, external_reference = NULL,
    delivered_at = CASE WHEN :status = 'DELIVERED' THEN CAST(:now AS timestamptz) ELSE NULL END,
    payload_expires_at = CASE WHEN :status = 'DELIVERED'
        THEN CAST(:now AS timestamptz) + INTERVAL '30 days' ELSE NULL END,
    next_attempt_at = CASE WHEN :status = 'RETRY_SCHEDULED'
        THEN CAST(:now AS timestamptz) + CAST(:delay AS integer) * INTERVAL '1 second' ELSE NULL END
WHERE id = :id AND status = :processing AND locked_by = :token AND locked_until > clock_timestamp()
SQL,
                [
                    ':status' => $decision->status, ':now' => self::timestamp($now), ':history' => $history,
                    ':error' => $decision->error?->value, ':delay' => $decision->delaySeconds ?? 0,
                    ':id' => $claim->outboxId, ':processing' => 'PROCESSING', ':token' => $claim->leaseToken,
                ],
            )->execute();
            $transaction->commit();

            return $updated === 1;
        } catch (OutboxRelayException $exception) {
            $this->rollBack($transaction);
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack($transaction);
            throw new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE, $exception, SafeCauseCode::PERSISTENCE);
        }
    }

    private function leaseMatches(OutboxRelayClaim $claim): bool
    {
        return (bool) $this->db->createCommand(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1 FROM {{%outbox_messages}}
    WHERE id = :id AND status = :status AND locked_by = :token AND locked_until > clock_timestamp()
)
SQL,
            [':id' => $claim->outboxId, ':status' => 'PROCESSING', ':token' => $claim->leaseToken],
        )->queryScalar();
    }

    /** @throws OutboxRelayException */
    private function requireNoCallerTransaction(): void
    {
        if ($this->db->getTransaction() !== null || $this->db->getMasterPdo()->inTransaction()) {
            throw new OutboxRelayException(OutboxRelayError::TRANSACTION_ALREADY_ACTIVE);
        }
    }

    private function rollBack(?Transaction $transaction): void
    {
        if ($transaction === null) {
            return;
        }
        try {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            } elseif ($this->db->pdo !== null && $this->db->pdo->inTransaction()) {
                // Yii decrements its transaction level before PDO commit can fail.
                $this->db->pdo->rollBack();
            }
        } catch (Throwable) {
            // A rollback failure must not replace the original persistence failure.
        }
    }

    private static function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
