<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\config;

use modules\platform\application\command\RunCriticalWorkerCommand;
use modules\platform\application\dto\CriticalWorkerSettings;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;

final class CriticalWorkerConfig
{
    private function __construct(
        public readonly RunCriticalWorkerCommand $command,
        public readonly CriticalWorkerSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $environment
     * @throws CriticalWorkerException
     */
    public static function fromEnvironment(array $environment, RabbitMqConnectionConfig $broker): self
    {
        $command = new RunCriticalWorkerCommand(
            self::integer($environment, 'MAX_MESSAGES', 1000),
            self::integer($environment, 'MAX_RUNTIME_SECONDS', 3600),
        );
        $settings = new CriticalWorkerSettings(
            self::integer($environment, 'HANDLER_TIMEOUT_SECONDS', 4),
            self::integer($environment, 'BROKER_OPERATION_TIMEOUT_SECONDS', 10),
            self::integer($environment, 'RECEIVE_TIMEOUT_SECONDS', 1),
            self::integer($environment, 'MEMORY_LIMIT_MIB', 256),
            self::integer($environment, 'SOFT_MEMORY_LIMIT_MIB', 192),
            self::integer($environment, 'SHUTDOWN_TIMEOUT_SECONDS', 10),
        );
        if (2 * $settings->handlerTimeoutSeconds + 2 > $broker->heartbeat
            || $settings->receiveTimeoutSeconds > $broker->heartbeat / 2
            || $broker->consumerPollTimeout > $broker->heartbeat / 2
        ) {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID);
        }

        return new self($command, $settings);
    }

    /** @param array<string, mixed> $environment */
    private static function integer(array $environment, string $field, int $default): int
    {
        $key = 'CRITICAL_WORKER_' . $field;
        if (!array_key_exists($key, $environment)) {
            return $default;
        }
        $value = $environment[$key];
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,4}$/D', $value) !== 1) {
            throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID);
        }

        return (int) $value;
    }
}
