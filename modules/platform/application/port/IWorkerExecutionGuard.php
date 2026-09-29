<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\exception\CriticalWorkerException;

interface IWorkerExecutionGuard
{
    /** @throws CriticalWorkerException */
    public function assertClean(): void;

    /** @throws CriticalWorkerException */
    public function close(): void;
}
