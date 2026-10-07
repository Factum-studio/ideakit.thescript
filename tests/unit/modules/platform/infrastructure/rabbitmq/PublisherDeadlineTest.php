<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure\rabbitmq;

use Codeception\Test\Unit;
use modules\platform\infrastructure\rabbitmq\PublisherStreamIo;
use modules\platform\infrastructure\rabbitmq\RabbitMqConnectionConfig;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\AMQPIOReader;
use PhpAmqpLib\Wire\IO\StreamIO;
use ReflectionProperty;

final class PublisherDeadlineTest extends Unit
{
    public function testContentReadWithZeroLibraryTimeoutStillHasDeadline(): void
    {
        $io = new PublisherStreamIo(self::configuration(), 0.02);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        stream_set_blocking($sockets[0], false);
        (new ReflectionProperty(StreamIO::class, 'sock'))->setValue($io, $sockets[0]);
        $reader = new AMQPIOReader($io, 0);
        $started = hrtime(true);
        try {
            $reader->read(1);
            self::fail('Expected bounded content read.');
        } catch (AMQPTimeoutException) {
            self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
        } finally {
            $io->close();
            fclose($sockets[1]);
        }
    }

    public function testContentReadDeadlineCanBeShorterThanPublicationCycle(): void
    {
        $io = new PublisherStreamIo(self::configuration(), 10.0);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        stream_set_blocking($sockets[0], false);
        (new ReflectionProperty(StreamIO::class, 'sock'))->setValue($io, $sockets[0]);
        $io->limitRead(0.02);
        try {
            (new AMQPIOReader($io, 0))->read(1);
            self::fail('Expected bounded confirmation content read.');
        } catch (AMQPTimeoutException) {
            $io->limitRead(null);
            fwrite($sockets[1], 'x');
            self::assertSame('x', $io->read(1));
        } finally {
            $io->close();
            fclose($sockets[1]);
        }
    }

    public function testBlockedWriteCannotExtendPublicationDeadline(): void
    {
        $io = new PublisherStreamIo(self::configuration(), 0.02);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        stream_set_blocking($sockets[0], false);
        (new ReflectionProperty(StreamIO::class, 'sock'))->setValue($io, $sockets[0]);
        $started = hrtime(true);
        try {
            $io->write(str_repeat('x', 1024 * 1024));
            self::fail('Expected bounded socket write.');
        } catch (AMQPTimeoutException) {
            self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
        } finally {
            $io->close();
            fclose($sockets[1]);
        }
    }

    private static function configuration(): RabbitMqConnectionConfig
    {
        return new RabbitMqConnectionConfig('127.0.0.1', 1, 'synthetic', 'synthetic-test-only', 'synthetic');
    }
}
