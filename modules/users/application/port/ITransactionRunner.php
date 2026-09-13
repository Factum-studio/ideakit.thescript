<?php

declare(strict_types=1);

namespace modules\users\application\port;

interface ITransactionRunner
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
