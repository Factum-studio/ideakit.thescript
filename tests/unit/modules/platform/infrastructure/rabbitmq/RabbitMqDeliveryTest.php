<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure\rabbitmq;

use Codeception\Test\Unit;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\infrastructure\rabbitmq\BrokerEnvelopeCodec;
use tests\fixtures\platform\TestOutboxRoutes;
use modules\platform\infrastructure\rabbitmq\RabbitMqDelivery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMqDeliveryTest extends Unit
{
    /** @dataProvider settlements */
    public function testFailedSettlementInvalidatesDeliveryWithoutClaimingSuccess(string $operation, string $method): void
    {
        $open = true;
        $invalidations = 0;
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['is_open', $method])
            ->getMock();
        $channel->method('is_open')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $channel->expects(self::once())->method($method)->with(7, false)
            ->willThrowException(new AMQPConnectionClosedException('synthetic-private-context'));
        $message = new AMQPMessage();
        $message->setChannel($channel)->setDeliveryInfo(7, false, 'ideakit.commands', 'critical');
        $delivery = new RabbitMqDelivery($message, new BrokerEnvelopeCodec(TestOutboxRoutes::registry()), static function () use (&$open, &$invalidations): void {
            $open = false;
            ++$invalidations;
        });

        foreach ([BrokerTransportErrorCode::CONNECTION_FAILURE, BrokerTransportErrorCode::DELIVERY_UNAVAILABLE] as $expected) {
            try {
                $delivery->$operation();
                self::fail('Expected unavailable settlement.');
            } catch (BrokerTransportException $exception) {
                self::assertSame($expected, $exception->errorCode);
                self::assertSame($expected->value, $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
        self::assertSame(1, $invalidations);
    }

    /** @return array<string, array{string, string}> */
    public static function settlements(): array
    {
        return ['ack' => ['acknowledge', 'basic_ack'], 'reject' => ['reject', 'basic_reject']];
    }
}
