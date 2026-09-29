<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\db;

use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\port\IWorkerExecutionGuard;
use Throwable;
use yii\db\Connection;

final class DbWorkerExecutionGuard implements IWorkerExecutionGuard
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @throws CriticalWorkerException */
    public function assertClean(): void
    {
        try {
            $dirty = $this->db->getTransaction() !== null || $this->db->pdo?->inTransaction() === true;
        } catch (Throwable) {
            throw new CriticalWorkerException(CriticalWorkerError::EXECUTION_SCOPE_DIRTY);
        }
        if ($dirty) {
            throw new CriticalWorkerException(CriticalWorkerError::EXECUTION_SCOPE_DIRTY);
        }
    }

    /** @throws CriticalWorkerException */
    public function close(): void
    {
        $failed = false;
        try {
            $transaction = $this->db->getTransaction();
            if ($transaction !== null) {
                $transaction->rollBack();
            } elseif ($this->db->pdo?->inTransaction()) {
                $this->db->pdo->rollBack();
            }
        } catch (Throwable) {
            $failed = true;
        }
        try {
            $this->db->close();
        } catch (Throwable) {
            $failed = true;
        }
        if ($failed) {
            throw new CriticalWorkerException(CriticalWorkerError::CLEANUP_FAILURE);
        }
    }
}
