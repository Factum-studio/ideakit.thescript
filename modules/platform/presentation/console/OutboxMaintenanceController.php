<?php

declare(strict_types=1);

namespace modules\platform\presentation\console;

use modules\platform\application\command\ClearDeliveredOutboxPayloadCommand;
use modules\platform\application\command\RecoverExpiredOutboxCommand;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\handler\ClearDeliveredOutboxPayloadHandler;
use modules\platform\application\handler\GetOutboxStatusHandler;
use modules\platform\application\handler\RecoverExpiredOutboxHandler;
use modules\platform\application\query\GetOutboxStatusQuery;
use Psr\Log\LoggerInterface;
use yii\base\Module;
use yii\console\Controller;
use yii\console\ExitCode;

class OutboxMaintenanceController extends Controller
{
    private const CLEANUP_DEFAULT_LIMIT = 100;

    public mixed $limit = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        string $id,
        Module $module,
        private readonly RecoverExpiredOutboxHandler $recovery,
        private readonly int $defaultLimit,
        private readonly ClearDeliveredOutboxPayloadHandler $cleanup,
        private readonly GetOutboxStatusHandler $status,
        private readonly LoggerInterface $logger,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /** @return list<string> */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return in_array($actionID, ['recover', 'clear-payload'], true)
            ? array_merge($options, ['limit']) : $options;
    }

    /** Recover a bounded batch of expired RabbitMQ outbox leases. */
    public function actionRecover(): int
    {
        $limit = $this->selectedLimit($this->defaultLimit);
        if ($limit === null) {
            $this->stderr("Invalid recovery limit.\n");

            return 2;
        }
        try {
            $receipt = $this->recovery->handle(new RecoverExpiredOutboxCommand($limit));
        } catch (OutboxMaintenanceException $exception) {
            $this->logger->warning('platform.outbox_maintenance.failed', [
                'operation' => 'recover', 'reason' => $exception->error->value,
            ]);
            $this->stderr('Outbox recovery failed: ' . $exception->error->value . ".\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->logger->info('platform.outbox_maintenance.recovered', [
            'retry_scheduled' => $receipt->retryScheduled, 'failed' => $receipt->failed,
        ]);
        $this->stdout(sprintf(
            "retry_scheduled=%d failed=%d\n",
            $receipt->retryScheduled,
            $receipt->failed,
        ));

        return ExitCode::OK;
    }

    /** Clear a bounded batch of due delivered outbox payloads. */
    public function actionClearPayload(): int
    {
        $limit = $this->selectedLimit(self::CLEANUP_DEFAULT_LIMIT);
        if ($limit === null) {
            $this->stderr("Invalid cleanup limit.\n");

            return 2;
        }
        try {
            $receipt = $this->cleanup->handle(new ClearDeliveredOutboxPayloadCommand($limit));
        } catch (OutboxMaintenanceException $exception) {
            $this->logger->warning('platform.outbox_maintenance.failed', [
                'operation' => 'clear_payload', 'reason' => $exception->error->value,
            ]);
            $this->stderr('Outbox cleanup failed: ' . $exception->error->value . ".\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->logger->info('platform.outbox_maintenance.payload_cleared', ['cleared' => $receipt->cleared]);
        $this->stdout(sprintf("cleared=%d\n", $receipt->cleared));

        return ExitCode::OK;
    }

    /** Read bounded technical outbox backlog counters. */
    public function actionStatus(): int
    {
        try {
            $view = $this->status->handle(new GetOutboxStatusQuery());
        } catch (OutboxMaintenanceException $exception) {
            $this->logger->warning('platform.outbox_maintenance.failed', [
                'operation' => 'status', 'reason' => $exception->error->value,
            ]);
            $this->stderr('Outbox status failed: ' . $exception->error->value . ".\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "failed=%d expired_leases=%d due=%d\n",
            $view->failed,
            $view->expiredLeases,
            $view->due,
        ));

        return ExitCode::OK;
    }

    private function selectedLimit(int $default): ?int
    {
        if ($this->limit === null) {
            return $default;
        }
        if (!is_string($this->limit) || preg_match('/\A[1-9][0-9]{0,2}\z/', $this->limit) !== 1 || (int) $this->limit > 100) {
            return null;
        }

        return (int) $this->limit;
    }
}
