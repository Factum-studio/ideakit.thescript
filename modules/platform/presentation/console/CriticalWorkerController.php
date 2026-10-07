<?php

declare(strict_types=1);

namespace modules\platform\presentation\console;

use modules\platform\application\command\RunCriticalWorkerCommand;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\handler\RunCriticalWorkerHandler;
use yii\base\Module;
use yii\console\Controller;
use yii\console\ExitCode;

class CriticalWorkerController extends Controller
{
    public mixed $limit = null;
    public mixed $maxRuntime = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        string $id,
        Module $module,
        private readonly RunCriticalWorkerHandler $handler,
        private readonly int $defaultLimit,
        private readonly int $defaultMaxRuntime,
        array $config = [],
    ) {
        parent::__construct($id, $module, $config);
    }

    /** @return list<string> */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit', 'maxRuntime']);
    }

    /** Process a bounded batch of critical background commands. */
    public function actionCritical(): int
    {
        $limit = self::option($this->limit, $this->defaultLimit, 10000);
        $maxRuntime = self::option($this->maxRuntime, $this->defaultMaxRuntime, 3600);
        if ($limit === null || $maxRuntime === null) {
            $this->stderr("configuration_invalid\n");

            return 2;
        }
        try {
            $receipt = $this->handler->handle(new RunCriticalWorkerCommand($limit, $maxRuntime));
        } catch (CriticalWorkerException $exception) {
            $this->stderr($exception->error->value . ' cause_code=' . $exception->causeCode->value . "\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf(
            "received=%d completed=%d already_completed=%d rejected=%d stop_reason=%s\n",
            $receipt->received,
            $receipt->completed,
            $receipt->alreadyCompleted,
            $receipt->rejected,
            $receipt->stopReason->value,
        ));

        return ExitCode::OK;
    }

    private static function option(mixed $value, int $default, int $maximum): ?int
    {
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,4}\z/', $value) !== 1) {
            return null;
        }
        $number = (int) $value;

        return $number <= $maximum ? $number : null;
    }
}
