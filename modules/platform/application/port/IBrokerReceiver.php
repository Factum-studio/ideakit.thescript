<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\exception\BrokerTransportException;

interface IBrokerReceiver
{
    /** @throws BrokerTransportException */
    public function receive(float $timeoutSeconds): ?IBrokerDelivery;

    /** @throws BrokerTransportException */
    public function close(): void;
}
