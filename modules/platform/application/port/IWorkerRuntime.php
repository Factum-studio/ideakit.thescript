<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\command\RunCriticalWorkerCommand;
use modules\platform\application\dto\CriticalWorkerSettings;
use modules\platform\application\exception\CriticalWorkerException;

interface IWorkerRuntime
{
    /** @throws CriticalWorkerException */
    public function start(RunCriticalWorkerCommand $command, CriticalWorkerSettings $settings): void;

    public function shouldStop(): bool;

    public function memoryLimitReached(): bool;

    public function monotonicSeconds(): float;

    /** @throws CriticalWorkerException */
    public function armDeadline(int $seconds): void;

    /** @throws CriticalWorkerException */
    public function disarmDeadline(): void;

    /** @throws CriticalWorkerException */
    public function close(): void;
}
