<?php

declare(strict_types=1);

namespace core\infrastructure\db;

use core\application\port\ITransactionManager;
use yii\db\Connection;
use yii\db\Exception;
use yii\db\Transaction;
use Throwable;

final class DbTransactionManager implements ITransactionManager
{
    private Connection $db;
    private ?Transaction $transaction = null;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function begin(): void
    {
        if ($this->transaction === null) {
            $this->transaction = $this->db->beginTransaction();
        }
    }

    /**
     * @throws Exception
     */
    public function commit(): void
    {
        if ($this->transaction !== null) {
            $this->transaction->commit();
            $this->transaction = null;
        }
    }

    public function rollback(): void
    {
        if ($this->transaction !== null) {
            $this->transaction->rollBack();
            $this->transaction = null;
        }
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function transactional(callable $callback): mixed
    {
        $transaction = $this->db->beginTransaction();
        try {
            $result = $callback($this);
            $transaction->commit();
            return $result;
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
