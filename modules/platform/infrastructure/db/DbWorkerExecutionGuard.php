<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\db;

use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\enum\SafeCauseCode;
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
        } catch (Throwable $exception) {
            throw new CriticalWorkerException(CriticalWorkerError::EXECUTION_SCOPE_DIRTY, $exception, SafeCauseCode::WORKER_RUNTIME);
        }
        if ($dirty) {
            throw new CriticalWorkerException(CriticalWorkerError::EXECUTION_SCOPE_DIRTY, causeCode: SafeCauseCode::WORKER_RUNTIME);
        }
    }

    /** @throws CriticalWorkerException */
    public function close(): void
    {
        $failure = null;
        try {
            $transaction = $this->db->getTransaction();
            if ($transaction !== null) {
                $transaction->rollBack();
            } elseif ($this->db->pdo?->inTransaction()) {
                $this->db->pdo->rollBack();
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        try {
            $this->db->close();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
        if ($failure !== null) {
            throw new CriticalWorkerException(CriticalWorkerError::CLEANUP_FAILURE, $failure, SafeCauseCode::WORKER_RUNTIME);
        }
    }
}
