<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\BrokerPublishReceipt;
use modules\platform\application\exception\BrokerTransportException;

interface IBrokerPublisher
{
    /** @throws BrokerTransportException */
    public function publish(BrokerEnvelope $envelope): BrokerPublishReceipt;
}
