<?php

declare(strict_types=1);

namespace modules\platform\application\handler;

use modules\platform\application\dto\OutboxStatusView;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\port\IOutboxStatusReader;
use modules\platform\application\query\GetOutboxStatusQuery;
use Throwable;

final class GetOutboxStatusHandler
{
    public function __construct(private readonly IOutboxStatusReader $reader)
    {
    }

    /** @throws OutboxMaintenanceException */
    public function handle(GetOutboxStatusQuery $query): OutboxStatusView
    {
        try {
            return $this->reader->getStatus();
        } catch (OutboxMaintenanceException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE);
        }
    }
}
