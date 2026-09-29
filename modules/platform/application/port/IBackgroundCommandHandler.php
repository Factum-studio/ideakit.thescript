<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\enum\BackgroundCommandOutcome;
use modules\platform\application\exception\BackgroundCommandRejectedException;

interface IBackgroundCommandHandler
{
    /** @throws BackgroundCommandRejectedException */
    public function handle(BrokerEnvelope $message): BackgroundCommandOutcome;
}
