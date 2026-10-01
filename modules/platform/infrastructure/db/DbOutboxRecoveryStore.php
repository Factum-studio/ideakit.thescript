<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\db;

use DateTimeImmutable;
use modules\platform\application\dto\OutboxRecoveryReceipt;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IOutboxRecoveryStore;
use Throwable;
use yii\db\Connection;
use yii\db\Transaction;

final class DbOutboxRecoveryStore implements IOutboxRecoveryStore
{
    public function __construct(
        private readonly Connection $db,
        private readonly OutboxRelayRowMapper $mapper,
        private readonly OutboxRetryPolicy $retryPolicy,
    ) {
    }

    /** @throws OutboxMaintenanceException */
    public function recoverExpired(int $limit, OutboxRelaySettings $settings): OutboxRecoveryReceipt
    {
        if ($limit < 1 || $limit > 100) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_LIMIT);
        }
        $retryScheduled = $failed = 0;
        for ($processed = 0; $processed < $limit; $processed++) {
            $status = $this->recoverOne($settings);
            if ($status === null) {
                break;
            }
            if ($status === 'RETRY_SCHEDULED') {
                $retryScheduled++;
            } else {
                $failed++;
            }
        }

        return new OutboxRecoveryReceipt($retryScheduled, $failed);
    }

    /** @throws OutboxMaintenanceException */
    private function recoverOne(OutboxRelaySettings $settings): ?string
    {
        $transaction = null;
        try {
            $this->requireNoCallerTransaction();
            $transaction = $this->db->beginTransaction();
            $row = $this->db->createCommand(
                <<<'SQL'
SELECT id, attempt_count, attempt_history, updated_at, locked_by, locked_until
FROM {{%outbox_messages}}
WHERE destination = :destination AND status = :processing AND locked_until <= clock_timestamp()
ORDER BY locked_until, id
LIMIT 1 FOR UPDATE SKIP LOCKED
SQL,
                [':destination' => 'RABBITMQ', ':processing' => 'PROCESSING'],
            )->queryOne();
            if ($row === false) {
                $transaction->commit();

                return null;
            }
            $now = new DateTimeImmutable($this->db->createCommand('SELECT clock_timestamp()')->queryScalar());
            $count = $row['attempt_count'];
            if (is_string($count) && preg_match('/^(0|[1-9][0-9]{0,4})$/D', $count) === 1) {
                $count = (int) $count;
            }
            $history = $row['attempt_history'];
            $decision = OutboxRelayDecision::failed(OutboxRelayError::INVALID_MESSAGE);
            if (is_int($count) && $count >= 1 && $count <= 32767 && is_string($history)) {
                try {
                    $decision = $this->retryPolicy->decide(OutboxRelayError::LEASE_EXPIRED, $count, $settings->maxAttempts);
                } catch (OutboxRelayException) {
                    throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
                }
                try {
                    $completed = $this->mapper->completeExpiredHistory(
                        $history,
                        $count,
                        new DateTimeImmutable($row['updated_at']),
                        $decision,
                        $now,
                    );
                    if ($completed === null) {
                        $decision = OutboxRelayDecision::failed(OutboxRelayError::ATTEMPT_LIMIT_REACHED);
                    } else {
                        $history = $completed;
                    }
                } catch (OutboxRelayException) {
                    $decision = OutboxRelayDecision::failed(OutboxRelayError::INVALID_MESSAGE);
                }
            }
            $updated = $this->db->createCommand(
                <<<'SQL'
UPDATE {{%outbox_messages}}
SET status = CAST(:status AS varchar(24)), attempt_history = CAST(:history AS jsonb),
    last_error_code = :error, updated_at = CAST(:now AS timestamptz),
    locked_by = NULL, locked_until = NULL,
    next_attempt_at = CASE WHEN :status = 'RETRY_SCHEDULED'
        THEN CAST(:now AS timestamptz) + CAST(:delay AS integer) * INTERVAL '1 second' ELSE NULL END
WHERE id = :id AND destination = :destination AND status = :processing
  AND locked_by = :owner AND locked_until = CAST(:until AS timestamptz)
  AND locked_until <= clock_timestamp()
SQL,
                [
                    ':status' => $decision->status, ':history' => $history,
                    ':error' => $decision->error?->value, ':now' => self::timestamp($now),
                    ':delay' => $decision->delaySeconds ?? 0, ':id' => $row['id'],
                    ':destination' => 'RABBITMQ', ':processing' => 'PROCESSING',
                    ':owner' => $row['locked_by'], ':until' => $row['locked_until'],
                ],
            )->execute();
            if ($updated !== 1) {
                throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
            }
            $transaction->commit();

            return $decision->status;
        } catch (OutboxMaintenanceException $exception) {
            $this->rollBack($transaction);
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack($transaction);
            throw new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE, $exception);
        }
    }

    /** @throws OutboxMaintenanceException */
    private function requireNoCallerTransaction(): void
    {
        if ($this->db->getTransaction() !== null || $this->db->getMasterPdo()->inTransaction()) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::TRANSACTION_ALREADY_ACTIVE);
        }
    }

    /** @throws OutboxMaintenanceException */
    private function rollBack(?Transaction $transaction): void
    {
        if ($transaction === null) {
            return;
        }
        try {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            } elseif ($this->db->pdo !== null && $this->db->pdo->inTransaction()) {
                $this->db->pdo->rollBack();
            }
        } catch (Throwable) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE);
        }
    }

    private static function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
