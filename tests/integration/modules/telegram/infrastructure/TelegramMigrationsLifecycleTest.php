<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use modules\telegram\infrastructure\migrations\m260913_090000_create_telegram_bot_sessions_table;
use modules\telegram\infrastructure\migrations\m260913_172000_create_telegram_updates_table;
use Yii;
use yii\db\Connection;
use yii\db\Transaction;

final class TelegramMigrationsLifecycleTest extends Unit
{
    private const TELEGRAM_MIGRATION_NAMESPACE = 'modules\\telegram\\infrastructure\\migrations';

    /** @var list<string> */
    private const TELEGRAM_TABLES = [
        'telegram_bot_sessions',
        'telegram_updates',
    ];

    /** @var list<string> */
    private const PARENT_TABLES = [
        'user',
        'user_identity',
        'telegram_identity_profiles',
    ];

    private Connection $db;
    private ?Transaction $transaction = null;

    protected function _before(): void
    {
        self::assertTrue(YII_ENV_TEST);

        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertContains(
            $db->createCommand('SELECT current_database()')->queryScalar(),
            ['ideakit_test', 'ideakit_tg29_3_test'],
        );

        $this->db = $db;
        $this->transaction = $db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transaction !== null && $this->transaction->getIsActive()) {
            $this->transaction->rollBack();
        }
    }

    public function testTelegramMigrationNamespaceIsRegisteredOnce(): void
    {
        $namespaces = require dirname(__DIR__, 5) . '/config/migration_namespaces.php';
        self::assertIsArray($namespaces);
        self::assertSame(
            1,
            count(array_filter(
                $namespaces,
                static fn (mixed $namespace): bool => $namespace === self::TELEGRAM_MIGRATION_NAMESPACE,
            )),
        );
    }

    public function testMigrationsCanBeRolledBackAndReappliedWithoutSchemaDrift(): void
    {
        $telegramSchemaBefore = $this->schemaFingerprint(self::TELEGRAM_TABLES);
        $parentSchemaBefore = $this->schemaFingerprint(self::PARENT_TABLES);
        $migrationHistoryBefore = $this->migrationHistory();

        $updateMigration = new m260913_172000_create_telegram_updates_table(['db' => $this->db]);
        $sessionMigration = new m260913_090000_create_telegram_bot_sessions_table(['db' => $this->db]);

        $updateMigration->safeDown();
        $sessionMigration->safeDown();

        self::assertSame([], $this->existingTables(self::TELEGRAM_TABLES));
        self::assertSame($parentSchemaBefore, $this->schemaFingerprint(self::PARENT_TABLES));
        self::assertSame($migrationHistoryBefore, $this->migrationHistory());

        $sessionMigration->safeUp();
        $updateMigration->safeUp();

        self::assertSame($telegramSchemaBefore, $this->schemaFingerprint(self::TELEGRAM_TABLES));
        self::assertSame($parentSchemaBefore, $this->schemaFingerprint(self::PARENT_TABLES));
        self::assertSame($migrationHistoryBefore, $this->migrationHistory());
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, array{
     *     columns: list<array<string, mixed>>,
     *     constraints: list<array<string, mixed>>,
     *     indexes: list<array<string, mixed>>
     * }>
     */
    private function schemaFingerprint(array $tables): array
    {
        $fingerprint = [];

        foreach ($tables as $table) {
            self::assertTrue($this->tableExists($table), $table);
            $fingerprint[$table] = [
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
SELECT tc.constraint_name, tc.constraint_type, pg_get_constraintdef(c.oid) AS definition
FROM information_schema.table_constraints AS tc
JOIN pg_namespace AS n ON n.nspname = tc.table_schema
JOIN pg_class AS r ON r.relname = tc.table_name AND r.relnamespace = n.oid
JOIN pg_constraint AS c ON c.conname = tc.constraint_name AND c.conrelid = r.oid
WHERE tc.table_schema = current_schema() AND tc.table_name = :table
ORDER BY tc.constraint_name
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

        return $fingerprint;
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function existingTables(array $tables): array
    {
        return array_values(array_filter(
            $tables,
            fn (string $table): bool => $this->tableExists($table),
        ));
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->createCommand(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = current_schema() AND table_name = :table
)
SQL,
            [':table' => $table],
        )->queryScalar();
    }

    /** @return list<array<string, mixed>> */
    private function migrationHistory(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->createCommand(
            'SELECT version, apply_time FROM {{%migration}} ORDER BY version',
        )->queryAll();

        return $rows;
    }
}
