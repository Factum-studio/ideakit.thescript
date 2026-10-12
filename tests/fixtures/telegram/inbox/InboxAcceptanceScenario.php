<?php

declare(strict_types=1);

namespace tests\fixtures\telegram\inbox;

use modules\platform\application\port\IOutboxWriter;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\enum\IncomingTelegramUpdateType;
use modules\telegram\application\handler\AcceptTelegramUpdateHandler;
use modules\telegram\application\port\ITelegramInboxTransactionRunner;
use modules\telegram\infrastructure\db\DbTelegramInboxStore;
use modules\telegram\infrastructure\db\DbTelegramInboxTransactionRunner;
use modules\telegram\infrastructure\db\TelegramAcceptanceDbFailure;
use modules\telegram\infrastructure\identity\RamseyTelegramAcceptanceIdGenerator;
use modules\telegram\infrastructure\time\SystemTelegramAcceptanceClock;
use RuntimeException;
use tests\fixtures\platform\PlatformTestEnvironment;
use tests\fixtures\platform\TestOutboxRoutes;
use yii\db\Connection;

final class InboxAcceptanceScenario
{
    public static function connection(): Connection
    {
        $environment = PlatformTestEnvironment::databaseEnvironment();
        $db = new Connection([
            'dsn' => $environment['TEST_DB_DSN'],
            'username' => $environment['TEST_DB_USERNAME'],
            'password' => $environment['TEST_DB_PASSWORD'],
            'enableLogging' => false,
            'enableProfiling' => false,
        ]);
        self::assertDatabase($db);

        return $db;
    }

    public static function assertDatabase(Connection $db): void
    {
        PlatformTestEnvironment::databaseEnvironment();
        if ($db->getDriverName() !== 'pgsql'
            || $db->createCommand('SELECT current_database()')->queryScalar() !== 'ideakit_test') {
            throw new RuntimeException('test_database_required');
        }
    }

    public static function handler(
        Connection $db,
        ?IOutboxWriter $writer = null,
        ?ITelegramInboxTransactionRunner $runner = null,
    ): AcceptTelegramUpdateHandler {
        $ids = new RamseyTelegramAcceptanceIdGenerator();

        return new AcceptTelegramUpdateHandler(
            new DbTelegramInboxStore($db, new SystemTelegramAcceptanceClock(), $ids),
            $runner ?? new DbTelegramInboxTransactionRunner($db, new TelegramAcceptanceDbFailure()),
            $writer ?? new DbOutboxWriter($db, TestOutboxRoutes::registry()),
            $ids,
        );
    }

    public static function command(string $botKey, string $body = '{"update_id":42}'): AcceptTelegramUpdateCommand
    {
        return new AcceptTelegramUpdateCommand(
            $botKey,
            42,
            IncomingTelegramUpdateType::MESSAGE,
            '123',
            null,
            $body,
            hash('sha256', $body),
        );
    }

    /** @return list<array<string, mixed>> */
    public static function inbox(Connection $db, string $botKey): array
    {
        return $db->createCommand('SELECT * FROM {{%telegram_updates}} WHERE bot_key = :bot', [':bot' => $botKey])->queryAll();
    }

    /** @return list<array<string, mixed>> */
    public static function outbox(Connection $db, string $botKey): array
    {
        return $db->createCommand(
            'SELECT * FROM {{%outbox_messages}} WHERE aggregate_id IN (SELECT id FROM {{%telegram_updates}} WHERE bot_key = :bot)',
            [':bot' => $botKey],
        )->queryAll();
    }

    public static function cleanup(Connection $db, string $botKey): void
    {
        self::assertDatabase($db);
        $db->createCommand(
            'DELETE FROM {{%outbox_messages}} WHERE aggregate_id IN (SELECT id FROM {{%telegram_updates}} WHERE bot_key = :bot)',
            [':bot' => $botKey],
        )->execute();
        $db->createCommand()->delete('{{%telegram_updates}}', ['bot_key' => $botKey])->execute();
    }
}
