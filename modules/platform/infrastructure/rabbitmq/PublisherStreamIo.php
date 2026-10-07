<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\IO\StreamIO;

final class PublisherStreamIo extends StreamIO
{
    private readonly float $deadline;
    private ?float $readDeadline = null;

    public function __construct(RabbitMqConnectionConfig $config, float $cycleTimeout)
    {
        $this->deadline = hrtime(true) / 1e9 + $cycleTimeout;
        parent::__construct(
            $config->host,
            $config->port,
            $config->connectionTimeout,
            min($config->readTimeout, $config->writeTimeout),
            null,
            false,
            $config->heartbeat,
        );
    }

    public function limitRead(?float $seconds): void
    {
        $this->readDeadline = $seconds === null ? null : hrtime(true) / 1e9 + $seconds;
    }

    public function connect(): void
    {
        $this->connection_timeout = min($this->connection_timeout, $this->remaining());
        parent::connect();
        if (!stream_set_blocking($this->stream(), false)) {
            throw new AMQPIOException('Publication stream unavailable.');
        }
        $this->remaining();
    }

    /** @param int $len */
    public function read($len): string
    {
        $this->check_heartbeat();
        $data = '';
        $progress = hrtime(true) / 1e9;
        while (strlen($data) < $len) {
            $this->remaining();
            $stream = $this->stream();
            if (feof($stream)) {
                throw new AMQPConnectionClosedException('Publication stream closed.');
            }
            $this->setErrorHandler();
            try {
                $buffer = fread($stream, $len - strlen($data));
                $this->throwOnError();
            } finally {
                $this->restoreErrorHandler();
            }
            if ($buffer === false) {
                throw new AMQPIOException('Publication read failed.');
            }
            if ($buffer === '') {
                $idleRemaining = $this->read_timeout - (hrtime(true) / 1e9 - $progress);
                if ($idleRemaining <= 0.0) {
                    throw new AMQPTimeoutException('Publication read timed out.');
                }
                $seconds = (int) $idleRemaining;
                $this->select($seconds, (int) (($idleRemaining - $seconds) * 1e6));
                continue;
            }
            $data .= $buffer;
            $this->last_read = microtime(true);
            $progress = hrtime(true) / 1e9;
        }
        $this->remaining();

        return $data;
    }

    /** @param string $data */
    public function write($data): void
    {
        $this->checkBrokerHeartbeat();
        $written = 0;
        $progress = hrtime(true) / 1e9;
        while ($written < strlen($data)) {
            $this->remaining();
            $stream = $this->stream();
            if (feof($stream)) {
                throw new AMQPConnectionClosedException('Publication stream closed.');
            }
            $this->setErrorHandler();
            try {
                $count = $this->select_write() ? fwrite($stream, substr($data, $written, self::BUFFER_SIZE)) : 0;
                $this->throwOnError();
            } finally {
                $this->restoreErrorHandler();
            }
            if ($count === false) {
                throw new AMQPIOException('Publication write failed.');
            }
            if ($count > 0) {
                $written += $count;
                $this->last_write = microtime(true);
                $progress = hrtime(true) / 1e9;
            } elseif (hrtime(true) / 1e9 - $progress >= $this->write_timeout) {
                throw new AMQPTimeoutException('Publication write timed out.');
            }
        }
        $this->remaining();
    }

    protected function do_select(?int $sec, int $usec): int|bool
    {
        // Content frames in basic.return otherwise use an unlimited library timeout.
        $seconds = min($this->remaining(), $sec === null ? INF : $sec + $usec / 1e6);
        $whole = (int) $seconds;
        $result = parent::do_select($whole, (int) (($seconds - $whole) * 1e6));
        $this->remaining();

        return $result;
    }

    protected function select_write(): int|bool
    {
        $this->remaining();
        $result = parent::select_write();
        $this->remaining();

        return $result;
    }

    /** @return resource */
    private function stream()
    {
        $stream = $this->getSocket();
        if (!is_resource($stream)) {
            throw new AMQPConnectionClosedException('Publication stream closed.');
        }

        return $stream;
    }

    private function remaining(): float
    {
        $remaining = min($this->deadline, $this->readDeadline ?? INF) - hrtime(true) / 1e9;
        if ($remaining <= 0.0) {
            throw new AMQPTimeoutException('Publication deadline exceeded.');
        }

        return $remaining;
    }
}
