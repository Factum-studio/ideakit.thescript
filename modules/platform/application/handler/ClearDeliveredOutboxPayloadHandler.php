<?php

declare(strict_types=1);

namespace modules\platform\application\handler;

use modules\platform\application\command\ClearDeliveredOutboxPayloadCommand;
use modules\platform\application\dto\OutboxPayloadCleanupReceipt;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\port\IOutboxPayloadCleanupStore;
use Throwable;

final class ClearDeliveredOutboxPayloadHandler
{
    public function __construct(private readonly IOutboxPayloadCleanupStore $store)
    {
    }

    /** @throws OutboxMaintenanceException */
    public function handle(ClearDeliveredOutboxPayloadCommand $command): OutboxPayloadCleanupReceipt
    {
        try {
            return $this->store->clearDue($command->limit);
        } catch (OutboxMaintenanceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE);
        }
    }
}
