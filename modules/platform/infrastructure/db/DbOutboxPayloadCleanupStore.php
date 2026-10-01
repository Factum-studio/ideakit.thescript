<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\db;

use modules\platform\application\dto\OutboxPayloadCleanupReceipt;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\port\IOutboxPayloadCleanupStore;
use Throwable;
use yii\db\Connection;
use yii\db\Transaction;

final class DbOutboxPayloadCleanupStore implements IOutboxPayloadCleanupStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @throws OutboxMaintenanceException */
    public function clearDue(int $limit): OutboxPayloadCleanupReceipt
    {
        if ($limit < 1 || $limit > 100) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_LIMIT);
        }
        $cleared = 0;
        for ($processed = 0; $processed < $limit; $processed++) {
            if (!$this->clearOne()) {
                break;
            }
            $cleared++;
        }

        return new OutboxPayloadCleanupReceipt($cleared);
    }

    /** @throws OutboxMaintenanceException */
    private function clearOne(): bool
    {
        $transaction = null;
        try {
            $this->requireNoCallerTransaction();
            $transaction = $this->db->beginTransaction();
            $id = $this->db->createCommand(
                <<<'SQL'
SELECT id FROM {{%outbox_messages}}
WHERE status = :delivered AND payload IS NOT NULL
  AND payload_expires_at <= clock_timestamp()
  AND delivered_at + INTERVAL '30 days' <= clock_timestamp()
ORDER BY payload_expires_at, id
LIMIT 1 FOR UPDATE SKIP LOCKED
SQL,
                [':delivered' => 'DELIVERED'],
            )->queryScalar();
            if ($id === false) {
                $transaction->commit();

                return false;
            }
            $updated = $this->db->createCommand(
                <<<'SQL'
UPDATE {{%outbox_messages}}
SET payload = NULL, updated_at = clock_timestamp()
WHERE id = :id AND status = :delivered AND payload IS NOT NULL
  AND payload_expires_at <= clock_timestamp()
  AND delivered_at + INTERVAL '30 days' <= clock_timestamp()
SQL,
                [':id' => $id, ':delivered' => 'DELIVERED'],
            )->execute();
            if ($updated !== 1) {
                throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
            }
            $transaction->commit();

            return true;
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
}
