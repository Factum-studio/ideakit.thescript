<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use Exception;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

final class RabbitMqConnectionFactory
{
    public function __construct(private readonly RabbitMqConnectionConfig $config)
    {
    }

    /** @throws BrokerTransportException */
    public function connect(): AbstractConnection
    {
        $connection = new AMQPConnectionConfig();
        $connection->setHost($this->config->host);
        $connection->setPort($this->config->port);
        $connection->setUser($this->config->user);
        $connection->setPassword($this->config->password);
        $connection->setVhost($this->config->vhost);
        $connection->setConnectionTimeout($this->config->connectionTimeout);
        $connection->setChannelRPCTimeout($this->config->channelRpcTimeout);
        $connection->setHeartbeat($this->config->heartbeat);
        $connection->setReadTimeout($this->config->readTimeout);
        $connection->setWriteTimeout($this->config->writeTimeout);
        $connection->setDebugPackets(false);
        try {
            return AMQPConnectionFactory::create($connection);
        } catch (Exception) {
            // Library exceptions can expose connection parameters through their context.
            throw new BrokerTransportException(BrokerTransportErrorCode::CONNECTION_FAILURE);
        }
    }
}
