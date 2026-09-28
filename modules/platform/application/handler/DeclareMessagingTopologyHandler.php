<?php

declare(strict_types=1);

namespace modules\platform\application\handler;

use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\port\IBrokerTopology;

final class DeclareMessagingTopologyHandler
{
    public function __construct(private readonly IBrokerTopology $topology)
    {
    }

    /** @throws BrokerTransportException */
    public function handle(): void
    {
        $this->topology->declare();
    }
}
