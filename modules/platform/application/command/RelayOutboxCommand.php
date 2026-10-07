<?php

declare(strict_types=1);

namespace modules\platform\application\command;

use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\exception\OutboxRelayException;

final class RelayOutboxCommand
{
    /** @throws OutboxRelayException */
    public function __construct(public readonly int $limit)
    {
        if ($limit < 1 || $limit > 100) {
            throw new OutboxRelayException(OutboxRelayError::CONFIGURATION_INVALID);
        }
    }
}
