<?php

declare(strict_types=1);

namespace tests\unit\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\infrastructure\logging\YiiWorkerLogger;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use Stringable;
use Yii;
use yii\log\Dispatcher;
use yii\log\Logger;
use yii\log\Target;

final class YiiWorkerLoggerTest extends Unit
{
    public function testOnlyAllowlistedEventAndContextReachTarget(): void
    {
        $target = self::target();
        $global = Yii::getLogger();
        $logger = new YiiWorkerLogger(new Dispatcher(['logger' => new Logger(), 'targets' => [$target]]));
        $logger->error('critical_worker.stopped', [
            'reason' => 'handler_failure', 'cleanup_failed' => true,
            'outbox_id' => '01890f4d-3c2a-7f48-8c0b-123456789ac4',
            'correlation_id' => 'not-a-uuid', 'exception' => new RuntimeException('synthetic-sensitive-input'),
            'payload' => 'synthetic-sensitive-input', 'sql' => 'synthetic-sensitive-input',
        ]);
        self::assertSame([
            'event' => 'critical_worker.stopped', 'reason' => 'handler_failure', 'cleanup_failed' => true,
            'outbox_id' => '01890f4d-3c2a-7f48-8c0b-123456789ac4',
        ], $target->last[0]);
        self::assertSame(Logger::LEVEL_ERROR, $target->last[1]);
        self::assertSame([], $target->last[4]);
        self::assertSame($global, Yii::getLogger());
        $logger->warning('critical_worker.rejected', ['reason' => 'synthetic-sensitive-input']);
        self::assertSame(['event' => 'critical_worker.rejected'], $target->last[0]);
        $before = $target->exports;
        $logger->info('synthetic-sensitive-input');
        $logger->info(new class () implements Stringable {
            public function __toString(): string
            {
                throw new RuntimeException('Untrusted event must not be converted.');
            }
        });
        self::assertSame($before, $target->exports);
    }

    public function testLongStreamFlushesBuffersIncludingStop(): void
    {
        $target = self::target();
        $logger = new YiiWorkerLogger(new Dispatcher(['logger' => new Logger(), 'targets' => [$target]]));
        $maximumBuffered = 0;
        foreach (range(1, 1500) as $iteration) {
            $logger->warning('critical_worker.rejected', ['reason' => 'invalid_envelope']);
            $maximumBuffered = max($maximumBuffered, count($target->messages));
        }
        $logger->info('critical_worker.stopped', ['reason' => 'message_limit']);
        self::assertSame(1501, $target->exports);
        self::assertSame(0, $maximumBuffered);
        self::assertSame([], $target->messages);
        self::assertSame(['event' => 'critical_worker.stopped', 'reason' => 'message_limit'], $target->last[0]);
    }

    public function testUnknownLevelFailsWithoutEchoingInput(): void
    {
        $logger = new YiiWorkerLogger(new Dispatcher(['logger' => new Logger(), 'targets' => []]));
        try {
            $logger->log('synthetic-sensitive-input', 'critical_worker.started');
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
