<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\exception\BrokerTransportException;

interface IBrokerTopology
{
    /** @throws BrokerTransportException */
    public function declare(): void;
}
