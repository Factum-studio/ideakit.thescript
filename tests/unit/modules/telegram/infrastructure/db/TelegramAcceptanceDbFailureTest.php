<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\db;

use Codeception\Test\Unit;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\telegram\infrastructure\db\TelegramAcceptanceDbFailure;
use PDOException;
use RuntimeException;
use yii\db\Exception;

final class TelegramAcceptanceDbFailureTest extends Unit
{
    /** @dataProvider states */
    public function testClassifiesOnlyStructuredAllowedStates(string $state, bool $transient, bool $input): void
    {
        $classifier = new TelegramAcceptanceDbFailure();
        $pdo = new PDOException('synthetic_sensitive_message');
        $pdo->errorInfo = [$state];
        $wrapped = new OutboxWriteException(OutboxWriteFailure::PERSISTENCE_FAILURE, $pdo);
        foreach ([$pdo, new Exception('synthetic_sensitive_message', [$state]), $wrapped] as $failure) {
            self::assertSame($transient, $classifier->isTransient($failure));
            self::assertSame($input, $classifier->isJsonInput($failure));
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function states(): iterable
    {
        foreach (['40001', '40P01', '55P03', '57014', '08001', '08003', '08006', '57P01', '57P02', '57P03', '53300'] as $state) {
            yield $state => [$state, true, false];
        }
        foreach (['22P05', '22003'] as $state) {
            yield $state => [$state, false, true];
        }
        foreach (['22P02', '23505', '23503', '23514', '28P01', '42P01', '08004', 'HY000', ''] as $state) {
            yield 'internal-' . $state => [$state, false, false];
        }
    }

    public function testIgnoresMessageAndLimitsPreviousTraversal(): void
    {
        $classifier = new TelegramAcceptanceDbFailure();
        self::assertFalse($classifier->isTransient(new RuntimeException('SQLSTATE[08006]')));
        self::assertFalse($classifier->isJsonInput(new RuntimeException('22P05')));
        $failure = new Exception('synthetic', ['08006']);
        for ($depth = 0; $depth < 20; ++$depth) {
            $failure = new RuntimeException('synthetic', 0, $failure);
        }
        self::assertFalse($classifier->isTransient($failure));
    }
}
