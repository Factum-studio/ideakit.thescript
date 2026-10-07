<?php

declare(strict_types=1);

namespace tests\fixtures\platform;

use modules\platform\application\command\RunCriticalWorkerCommand;
use modules\platform\application\dto\BackgroundCommandRegistration;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\CriticalWorkerSettings;
use modules\platform\application\enum\BackgroundCommandOutcome;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\handler\RunCriticalWorkerHandler;
use modules\platform\application\port\IBackgroundCommandHandler;
use modules\platform\application\port\IBrokerDelivery;
use modules\platform\application\port\IBrokerReceiver;
use modules\platform\application\port\IWorkerExecutionGuard;
use modules\platform\application\port\IWorkerRuntime;
use modules\platform\application\route\BackgroundCommandRegistry;
use modules\platform\infrastructure\logging\YiiWorkerLogger;
use RuntimeException;
use yii\log\Dispatcher;

final class CriticalWorkerFailureProbe
{
    public static function handler(Dispatcher $dispatcher, ?BrokerTransportErrorCode $receiverFailure = null): RunCriticalWorkerHandler
    {
        $receiver = new class ($receiverFailure) implements IBrokerReceiver {
            public function __construct(private readonly ?BrokerTransportErrorCode $failure)
            {
            }

            public function receive(float $timeoutSeconds): ?IBrokerDelivery
            {
                if ($this->failure !== null) {
                    throw new BrokerTransportException($this->failure);
                }
                throw new RuntimeException('synthetic-sensitive-unexpected-receive');
            }

            public function close(): void
            {
            }
        };
        $handler = new class () implements IBackgroundCommandHandler {
            public function handle(BrokerEnvelope $message): BackgroundCommandOutcome
            {
                throw new RuntimeException('synthetic-sensitive-unexpected-handler');
            }
        };
        $runtime = new class ($receiverFailure === null) implements IWorkerRuntime {
            public function __construct(private readonly bool $failOnStart)
            {
            }

            public function start(RunCriticalWorkerCommand $command, CriticalWorkerSettings $settings): void
            {
                if ($this->failOnStart) {
                    throw new RuntimeException('synthetic-sensitive-runtime');
                }
            }

            public function shouldStop(): bool
            {
                return false;
            }

            public function memoryLimitReached(): bool
            {
                return false;
            }

            public function monotonicSeconds(): float
            {
                return 0.0;
            }

            public function armDeadline(int $seconds): void
            {
            }

            public function disarmDeadline(): void
            {
            }

            public function close(): void
            {
            }
        };
        $guard = new class () implements IWorkerExecutionGuard {
            public function assertClean(): void
            {
            }

            public function close(): void
            {
            }
        };

        return new RunCriticalWorkerHandler(
            $receiver,
            new BackgroundCommandRegistry([
                new BackgroundCommandRegistration('telegram.update.received', '1.0', $handler),
            ], TestOutboxRoutes::registry()),
            $runtime,
            $guard,
            new CriticalWorkerSettings(1, 1, 1, 64, 32, 2),
            new YiiWorkerLogger($dispatcher),
        );
    }
}
