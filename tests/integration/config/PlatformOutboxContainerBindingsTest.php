<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\port\IOutboxWriter;
use modules\platform\infrastructure\db\DbOutboxWriter;
use Yii;
use yii\db\Connection;
use yii\di\Container;

final class PlatformOutboxContainerBindingsTest extends Unit
{
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789be1';
    private const CORRELATION_ID = '01890f4d-3c2a-7f48-8c0b-123456789be2';

    /** @dataProvider applicationConfigurations */
    public function testProductionConfigurationUsesCallerDatabaseTransaction(string $configuration): void
    {
        self::assertTrue(YII_ENV_TEST);
        $connection = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame('ideakit_test', $connection->createCommand('SELECT current_database()')->queryScalar());

        $originalContainer = Yii::$container;
        Yii::$container = new Container();
        try {
            $config = require dirname(__DIR__, 3) . '/config/' . $configuration . '.php';
            self::assertIsArray($config);
            $writer = Yii::$container->get(IOutboxWriter::class);
            self::assertInstanceOf(DbOutboxWriter::class, $writer);

            $transaction = $connection->beginTransaction();
            try {
                $receipt = $writer->write(new OutboxWriteIntent(
                    'Telegram',
                    'telegram.update.received',
                    '1.0',
                    'TELEGRAM_UPDATE',
                    self::UPDATE_ID,
                    new TelegramUpdateReceivedPayload(self::UPDATE_ID),
                    'platform-container-test',
                    self::CORRELATION_ID,
                ));
                self::assertSame(OutboxWriteOutcome::CREATED, $receipt->outcome);
                self::assertSame($receipt->outboxMessageId, $connection->createCommand(
                    'SELECT id FROM {{%outbox_messages}} WHERE idempotency_key = :key',
                    [':key' => 'platform-container-test'],
                )->queryScalar());
            } finally {
                if ($transaction->getIsActive()) {
                    $transaction->rollBack();
                }
            }
            self::assertSame(0, (int) $connection->createCommand(
                'SELECT count(*) FROM {{%outbox_messages}} WHERE idempotency_key = :key',
                [':key' => 'platform-container-test'],
            )->queryScalar());
        } finally {
            Yii::$container = $originalContainer;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function applicationConfigurations(): iterable
    {
        yield 'web' => ['web'];
        yield 'console' => ['console'];
    }
}
