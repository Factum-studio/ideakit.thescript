<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\exception\BrokerTransportException;

interface IBrokerDelivery
{
    /** @throws BrokerTransportException */
    public function message(): BrokerEnvelope;

    /** @throws BrokerTransportException */
    public function acknowledge(): void;

    /** @throws BrokerTransportException */
    public function reject(): void;
}
