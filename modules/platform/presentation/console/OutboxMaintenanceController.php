<?php

declare(strict_types=1);

namespace modules\platform\presentation\console;

use modules\platform\application\command\RecoverExpiredOutboxCommand;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\handler\RecoverExpiredOutboxHandler;
use yii\base\Module;
use yii\console\Controller;
use yii\console\ExitCode;

class OutboxMaintenanceController extends Controller
{
    public mixed $limit = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        string $id,
        Module $module,
        private readonly RecoverExpiredOutboxHandler $recovery,
        private readonly int $defaultLimit,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /** @return list<string> */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit']);
    }

    /** Recover a bounded batch of expired RabbitMQ outbox leases. */
    public function actionRecover(): int
    {
        $limit = $this->defaultLimit;
        if ($this->limit !== null) {
            if (!is_string($this->limit) || preg_match('/\A[1-9][0-9]{0,2}\z/', $this->limit) !== 1 || (int) $this->limit > 100) {
                $this->stderr("Invalid recovery limit.\n");

                return 2;
            }
            $limit = (int) $this->limit;
        }
        try {
            $receipt = $this->recovery->handle(new RecoverExpiredOutboxCommand($limit));
        } catch (OutboxMaintenanceException $exception) {
            $this->stderr('Outbox recovery failed: ' . $exception->error->value . ".\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "retry_scheduled=%d failed=%d\n",
            $receipt->retryScheduled,
            $receipt->failed,
        ));

        return ExitCode::OK;
    }
}
