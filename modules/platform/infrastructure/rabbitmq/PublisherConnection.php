<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;

final class PublisherConnection extends AbstractConnection
{
    public function __construct(
        AMQPConnectionConfig $config,
        private readonly PublisherStreamIo $stream,
    ) {
        parent::__construct(
            $config->getUser(),
            $config->getPassword(),
            $config->getVhost(),
            $config->isInsist(),
            $config->getLoginMethod(),
            $config->getLoginResponse(),
            $config->getLocale(),
            $stream,
            $config->getHeartbeat(),
            $config->getConnectionTimeout(),
            $config->getChannelRPCTimeout(),
            $config,
        );
    }

    public function waitForConfirmation(AMQPChannel $channel, float $seconds): void
    {
        $this->stream->limitRead($seconds);
        try {
            $channel->wait(null, false, $seconds);
        } finally {
            $this->stream->limitRead(null);
        }
    }
}
