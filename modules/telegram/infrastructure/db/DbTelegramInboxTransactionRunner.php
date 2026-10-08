<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\db;

use modules\platform\application\exception\OutboxWriteException;
use modules\telegram\application\dto\TelegramInboxReservation;
use modules\telegram\application\exception\InvalidTelegramUpdatePayloadException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceIntegrityException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;
use modules\telegram\application\port\ITelegramInboxTransactionRunner;
use PDOException;
use Throwable;
use yii\db\Connection;
use yii\db\Exception;
use yii\db\Transaction;

final class DbTelegramInboxTransactionRunner implements ITelegramInboxTransactionRunner
{
    public function __construct(
        private readonly Connection $db,
        private readonly TelegramAcceptanceDbFailure $failures,
    ) {
    }

    public function run(callable $operation): TelegramInboxReservation
    {
        if ($this->db->getDriverName() !== 'pgsql' || $this->db->getTransaction() !== null
            || ($this->db->pdo !== null && $this->db->pdo->inTransaction())) {
            throw new TelegramUpdateAcceptanceIntegrityException();
        }

        $transaction = null;
        $committing = false;
        try {
            $transaction = $this->db->beginTransaction();
            $transaction->setIsolationLevel(Transaction::READ_COMMITTED);
            $this->db->createCommand("SET LOCAL TIME ZONE 'UTC'")->execute();
            $this->db->createCommand("SET LOCAL lock_timeout = '2s'")->execute();
            $this->db->createCommand("SET LOCAL statement_timeout = '5s'")->execute();
            $result = $this->reservation($operation());
            $this->assertOwnedTransaction($transaction);
            $committing = true;
            $transaction->commit();

            return $result;
        } catch (Throwable $failure) {
            if ($committing) {
                $this->close();
                throw new TelegramUpdateAcceptanceUnavailableException($failure);
            }
            $this->rollback($transaction);
            if ($failure instanceof InvalidTelegramUpdatePayloadException
                || $failure instanceof TelegramUpdateAcceptanceIntegrityException
                || $failure instanceof TelegramUpdateAcceptanceUnavailableException) {
                throw $failure;
            }
            if ($this->failures->isTransient($failure)) {
                throw new TelegramUpdateAcceptanceUnavailableException($failure);
            }
            if ($failure instanceof Exception || $failure instanceof PDOException || $failure instanceof OutboxWriteException) {
                throw new TelegramUpdateAcceptanceIntegrityException($failure);
            }
            throw $failure;
        }
    }

    private function reservation(mixed $result): TelegramInboxReservation
    {
        if (!$result instanceof TelegramInboxReservation) {
            throw new TelegramUpdateAcceptanceIntegrityException();
        }

        return $result;
    }

    private function assertOwnedTransaction(Transaction $transaction): void
    {
        if ($this->db->getTransaction() !== $transaction || !$transaction->getIsActive()
            || $transaction->getLevel() !== 1 || $this->db->pdo === null || !$this->db->pdo->inTransaction()) {
            throw new TelegramUpdateAcceptanceIntegrityException();
        }
    }

    private function rollback(?Transaction $transaction): void
    {
        try {
            if ($transaction !== null && $this->db->getTransaction() === $transaction
                && $transaction->getIsActive() && $transaction->getLevel() === 1) {
                $transaction->rollBack();
                if ($this->db->pdo !== null && !$this->db->pdo->inTransaction()) {
                    return;
                }
            }
        } catch (Throwable) {
            // Cleanup failure cannot replace the original acceptance failure.
        }
        $this->close();
    }

    private function close(): void
    {
        try {
            $this->db->close();
        } catch (Throwable) {
            // The original failure remains authoritative even if connection cleanup fails.
        }
    }
}
