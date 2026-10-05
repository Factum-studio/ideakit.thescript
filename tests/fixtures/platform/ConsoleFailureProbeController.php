<?php

declare(strict_types=1);

namespace tests\fixtures\platform;

use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\exception\OutboxWriteException;
use RuntimeException;
use yii\console\Controller;

final class ConsoleFailureProbeController extends Controller
{
    public function actionFail(string $kind = 'unexpected'): int
    {
        $cause = new RuntimeException('synthetic-sensitive-outer', 0, new RuntimeException('synthetic-sensitive-inner'));
        if ($kind === 'closed-stderr') {
            fclose(STDERR);
        }

        throw match ($kind) {
            'worker' => new CriticalWorkerException(CriticalWorkerError::HANDLER_FAILURE, $cause, SafeCauseCode::HANDLER),
            'relay' => new OutboxRelayException(OutboxRelayError::PERSISTENCE_FAILURE, $cause, SafeCauseCode::PERSISTENCE),
            'maintenance' => new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE, $cause, SafeCauseCode::PERSISTENCE),
            'broker' => new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE),
            'writer' => new OutboxWriteException(OutboxWriteFailure::PERSISTENCE_FAILURE, $cause),
            'writer-conflict' => new OutboxWriteException(OutboxWriteFailure::IDEMPOTENCY_CONFLICT, $cause),
            default => $cause,
        };
    }
}
