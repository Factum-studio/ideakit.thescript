<?php

declare(strict_types=1);

namespace core\application\port;

interface ITransactionManager
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function transactional(callable $callback): mixed;
}
