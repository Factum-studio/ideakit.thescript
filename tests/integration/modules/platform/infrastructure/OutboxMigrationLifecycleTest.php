<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\infrastructure\migrations\m260926_090000_create_outbox_messages_table;
use Yii;
use yii\db\Connection;
use yii\db\Transaction;

final class OutboxMigrationLifecycleTest extends Unit
{
    private Connection $db;
    private ?Transaction $transaction = null;

    protected function _before(): void
    {
        self::assertTrue(YII_ENV_TEST);
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertSame('ideakit_test', $db->createCommand('SELECT current_database()')->queryScalar());
        $this->db = $db;
        $this->transaction = $db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transaction !== null && $this->transaction->getIsActive()) {
            $this->transaction->rollBack();
        }
    }

    public function testRegistersPlatformMigrationNamespaceOnce(): void
    {
        $namespaces = require dirname(__DIR__, 5) . '/config/migration_namespaces.php';
        self::assertIsArray($namespaces);
        self::assertSame(1, count(array_filter(
            $namespaces,
            static fn (mixed $name): bool => $name === 'modules\\platform\\infrastructure\\migrations',
        )));
    }

    public function testRollbackAndReapplyPreserveParentSchemasAndMigrationHistory(): void
    {
        $outboxBefore = $this->fingerprint('outbox_messages');
        $userBefore = $this->fingerprint('user');
        $profileBefore = $this->fingerprint('telegram_identity_profiles');
        $historyBefore = $this->db->createCommand(
            'SELECT version, apply_time FROM {{%migration}} ORDER BY version',
        )->queryAll();
        self::assertSame(0, (int) $this->db->createCommand(
            'SELECT count(*) FROM {{%outbox_messages}}',
        )->queryScalar());

        $migration = new m260926_090000_create_outbox_messages_table(['db' => $this->db]);
        $migration->safeDown();

        self::assertFalse($this->tableExists('outbox_messages'));
        self::assertSame($userBefore, $this->fingerprint('user'));
        self::assertSame($profileBefore, $this->fingerprint('telegram_identity_profiles'));
        self::assertSame($historyBefore, $this->db->createCommand(
            'SELECT version, apply_time FROM {{%migration}} ORDER BY version',
        )->queryAll());

        $migration->safeUp();

        self::assertSame($outboxBefore, $this->fingerprint('outbox_messages'));
        self::assertSame($userBefore, $this->fingerprint('user'));
        self::assertSame($profileBefore, $this->fingerprint('telegram_identity_profiles'));
        self::assertSame($historyBefore, $this->db->createCommand(
            'SELECT version, apply_time FROM {{%migration}} ORDER BY version',
        )->queryAll());
    }

    /**
     * @return array{
     *     columns: list<array<string, mixed>>,
     *     constraints: list<array<string, mixed>>,
     *     indexes: list<array<string, mixed>>
     * }
     */
    private function fingerprint(string $table): array
    {
        self::assertTrue($this->tableExists($table), $table);

        return [
            'columns' => $this->db->createCommand(
                <<<'SQL'
SELECT column_name, udt_name, is_nullable, character_maximum_length, column_default
FROM information_schema.columns
WHERE table_schema = current_schema() AND table_name = :table
ORDER BY ordinal_position
SQL,
                [':table' => $table],
            )->queryAll(),
            'constraints' => $this->db->createCommand(
                <<<'SQL'
SELECT conname, contype, confdeltype, confupdtype, pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = CAST(:table AS regclass)
ORDER BY conname
SQL,
                [':table' => $table],
            )->queryAll(),
            'indexes' => $this->db->createCommand(
                <<<'SQL'
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = current_schema() AND tablename = :table
ORDER BY indexname
SQL,
                [':table' => $table],
            )->queryAll(),
        ];
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->createCommand(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = current_schema() AND table_name = :table
)
SQL,
            [':table' => $table],
        )->queryScalar();
    }
}
