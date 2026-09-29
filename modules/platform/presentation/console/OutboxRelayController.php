<?php

declare(strict_types=1);

namespace modules\platform\presentation\console;

use modules\platform\application\command\RelayOutboxCommand;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\handler\RelayOutboxHandler;
use yii\base\Module;
use yii\console\Controller;
use yii\console\ExitCode;

class OutboxRelayController extends Controller
{
    public mixed $limit = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        string $id,
        Module $module,
        private readonly RelayOutboxHandler $handler,
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

    /** Publish a bounded batch of ready outbox messages. */
    public function actionRelay(): int
    {
        $limit = $this->defaultLimit;
        if ($this->limit !== null) {
            if (!is_string($this->limit) || preg_match('/\A[1-9][0-9]{0,2}\z/', $this->limit) !== 1 || (int) $this->limit > 100) {
                $this->stderr("Invalid relay limit.\n");

                return 2;
            }
            $limit = (int) $this->limit;
        }
        try {
            $receipt = $this->handler->handle(new RelayOutboxCommand($limit));
        } catch (OutboxRelayException $exception) {
            $this->stderr('Outbox relay failed: ' . $exception->error->value . ".\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "claimed=%d delivered=%d retry_scheduled=%d failed=%d lease_lost=%d\n",
            $receipt->claimed,
            $receipt->delivered,
            $receipt->retryScheduled,
            $receipt->failed,
            $receipt->leaseLost,
        ));

        return ExitCode::OK;
    }
}
