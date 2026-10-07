<?php

declare(strict_types=1);

namespace modules\platform\application\handler;

use modules\platform\application\command\RecoverExpiredOutboxCommand;
use modules\platform\application\dto\OutboxRecoveryReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\port\IOutboxRecoveryStore;
use Throwable;

final class RecoverExpiredOutboxHandler
{
    public function __construct(
        private readonly IOutboxRecoveryStore $store,
        private readonly OutboxRelaySettings $settings,
    ) {
    }

    /** @throws OutboxMaintenanceException */
    public function handle(RecoverExpiredOutboxCommand $command): OutboxRecoveryReceipt
    {
        try {
            return $this->store->recoverExpired($command->limit, $this->settings);
        } catch (OutboxMaintenanceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE, $exception, SafeCauseCode::PERSISTENCE);
        }
    }
}
