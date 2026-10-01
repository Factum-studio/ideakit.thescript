<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\infrastructure\logging\YiiOutboxMaintenanceLogger;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use Stringable;
use Yii;
use yii\log\Dispatcher;
use yii\log\Logger;
use yii\log\Target;

final class YiiOutboxMaintenanceLoggerTest extends Unit
{
    public function testOnlyTechnicalEventsAndCountsAreLogged(): void
    {
        $target = self::target();
        $global = Yii::getLogger();
        $logger = new YiiOutboxMaintenanceLogger(new Dispatcher(['logger' => new Logger(), 'targets' => [$target]]));

        $logger->info('platform.outbox_maintenance.recovered', [
            'retry_scheduled' => 2, 'failed' => 1, 'payload' => 'synthetic-sensitive-input',
        ]);
        self::assertSame(['event' => 'platform.outbox_maintenance.recovered', 'retry_scheduled' => 2, 'failed' => 1], $target->last[0]);
        self::assertSame(Logger::LEVEL_INFO, $target->last[1]);
        self::assertSame('platform.outbox_maintenance', $target->last[2]);
        self::assertSame([], $target->last[4]);

        $logger->info('platform.outbox_maintenance.payload_cleared', ['cleared' => 3, 'id' => 'synthetic-sensitive-input']);
        self::assertSame(['event' => 'platform.outbox_maintenance.payload_cleared', 'cleared' => 3], $target->last[0]);

        $logger->warning('platform.outbox_maintenance.failed', [
            'operation' => 'clear_payload', 'reason' => 'persistence_failure',
            'exception' => new RuntimeException('synthetic-sensitive-input'),
            'previous' => new RuntimeException('synthetic-inner-detail'),
        ]);
        self::assertSame([
            'event' => 'platform.outbox_maintenance.failed',
            'operation' => 'clear_payload', 'reason' => 'persistence_failure',
        ], $target->last[0]);
        self::assertSame(Logger::LEVEL_WARNING, $target->last[1]);

        $before = $target->exports;
        $logger->info('synthetic-sensitive-input');
        $logger->info(new class () implements Stringable {
            public function __toString(): string
            {
                throw new RuntimeException('Untrusted event must not be converted.');
            }
        });
        self::assertSame($before, $target->exports);
        self::assertSame($global, Yii::getLogger());
    }

    public function testInvalidContextAndLevelCannotLeakUntrustedValues(): void
    {
        $target = self::target();
        $logger = new YiiOutboxMaintenanceLogger(new Dispatcher(['logger' => new Logger(), 'targets' => [$target]]));
        $logger->warning('platform.outbox_maintenance.failed', [
            'operation' => 'synthetic-sensitive-input', 'reason' => 'synthetic-sensitive-input',
        ]);
        self::assertSame(['event' => 'platform.outbox_maintenance.failed'], $target->last[0]);

        $logger->info('platform.outbox_maintenance.payload_cleared', ['cleared' => -1]);
        self::assertSame(['event' => 'platform.outbox_maintenance.payload_cleared'], $target->last[0]);

        try {
            $logger->log('synthetic-sensitive-input', 'platform.outbox_maintenance.recovered');
            self::fail('Expected invalid log level.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('invalid_log_level', $exception->getMessage());
        }
    }

    private static function target(): Target
    {
        return new class (['logVars' => []]) extends Target {
            public int $exports = 0;
            /** @var array<int, mixed> */
            public array $last = [];

            public function export(): void
            {
                $this->exports++;
                $this->last = $this->messages[0];
            }
        };
    }
}
