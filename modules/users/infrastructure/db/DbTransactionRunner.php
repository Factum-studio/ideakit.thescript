<?php

declare(strict_types=1);

namespace modules\users\infrastructure\db;

use core\application\port\ITransactionManager;
use modules\users\application\port\ITransactionRunner;

final class DbTransactionRunner implements ITransactionRunner
{
    public function __construct(
        private readonly ITransactionManager $transactionManager,
    ) {
    }

    public function run(callable $operation): mixed
    {
        return $this->transactionManager->transactional(
            static fn (ITransactionManager $_transactionManager): mixed => $operation(),
        );
    }
}
