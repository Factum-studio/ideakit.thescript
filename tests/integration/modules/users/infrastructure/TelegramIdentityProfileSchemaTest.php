<?php

declare(strict_types=1);

namespace tests\integration\modules\users\infrastructure;

use Codeception\Test\Unit;
use modules\users\infrastructure\persistence\TelegramIdentityProfileAR;
use Yii;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\Transaction;

final class TelegramIdentityProfileSchemaTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab0';
    private const USER_IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab1';
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab2';
    private const SECOND_PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab3';

    private Connection $db;
    private Transaction $transaction;

    protected function _before(): void
    {
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);

        $this->db = $db;
        $this->transaction = $db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transaction->getIsActive()) {
            $this->transaction->rollBack();
        }
    }

    public function testUsesApprovedPostgreSqlColumnTypes(): void
    {
        $expectedTypes = [
            'id' => 'uuid',
            'user_identity_id' => 'uuid',
            'username' => 'character varying',
            'first_name' => 'character varying',
            'last_name' => 'character varying',
            'language_code' => 'character varying',
            'bot_status' => 'character varying',
            'catalog_exhausted_through_sequence_no' => 'bigint',
            'catalog_exhausted_at' => 'timestamp with time zone',
            'catalog_notified_through_sequence_no' => 'bigint',
            'first_seen_at' => 'timestamp with time zone',
            'last_seen_at' => 'timestamp with time zone',
            'blocked_at' => 'timestamp with time zone',
            'lock_version' => 'bigint',
            'created_at' => 'timestamp with time zone',
            'updated_at' => 'timestamp with time zone',
        ];

        foreach ($expectedTypes as $column => $expectedType) {
            self::assertSame($expectedType, $this->columnType($column), $column);
        }

        self::assertSame(64, $this->columnLength('username'));
        self::assertSame(255, $this->columnLength('first_name'));
        self::assertSame(255, $this->columnLength('last_name'));
        self::assertSame(16, $this->columnLength('language_code'));
        self::assertSame(16, $this->columnLength('bot_status'));
    }

    public function testDefinesNamedConstraintsAndRestrictiveIdentityForeignKey(): void
    {
        $constraints = $this->constraints();

        self::assertSame('p', $constraints['pk_telegram_identity_profiles']['type'] ?? null);
        self::assertSame('u', $constraints['uq_telegram_identity_profiles_user_identity_id']['type'] ?? null);

        $foreignKey = $constraints['fk_telegram_identity_profiles_user_identity_id'] ?? null;
        self::assertSame('f', $foreignKey['type'] ?? null);
        self::assertSame('r', $foreignKey['update_action'] ?? null);
        self::assertSame('r', $foreignKey['delete_action'] ?? null);

        foreach (self::checkConstraintNames() as $constraintName) {
            self::assertSame('c', $constraints[$constraintName]['type'] ?? null, $constraintName);
        }
    }

    public function testDefinesBotStatusLastSeenIndex(): void
    {
        $definition = $this->db->createCommand(
            <<<'SQL'
SELECT indexdef
FROM pg_indexes
WHERE schemaname = current_schema()
  AND tablename = 'telegram_identity_profiles'
  AND indexname = 'idx_telegram_identity_profiles_bot_status_last_seen_at'
SQL,
        )->queryScalar();

        self::assertIsString($definition);
        self::assertStringContainsString('(bot_status, last_seen_at)', $definition);
    }

    public function testActiveRecordMapsTableAndOptimisticLock(): void
    {
        self::assertSame('{{%telegram_identity_profiles}}', TelegramIdentityProfileAR::tableName());
        self::assertSame('lock_version', (new TelegramIdentityProfileAR())->optimisticLock());
    }

    public function testAcceptsValidProfileAndAppliesTechnicalDefaults(): void
    {
        $this->insertCoreIdentity();
        $this->insertProfile();

        $row = $this->db->createCommand(
            'SELECT lock_version, created_at, updated_at FROM {{%telegram_identity_profiles}} WHERE id = :id',
            [':id' => self::PROFILE_ID],
        )->queryOne();

        self::assertIsArray($row);
        self::assertSame(0, (int) $row['lock_version']);
        self::assertNotEmpty($row['created_at']);
        self::assertNotEmpty($row['updated_at']);
    }

    /**
     * @dataProvider invalidProfileRows
     *
     * @param array<string, int|string|null> $overrides
     */
    public function testRejectsRowsViolatingNamedChecks(array $overrides): void
    {
        $this->insertCoreIdentity();

        $this->expectException(IntegrityException::class);

        $this->insertProfile($overrides);
    }

    /**
     * @return iterable<string, array{array<string, int|string|null>}>
     */
    public static function invalidProfileRows(): iterable
    {
        yield 'unsupported bot status' => [['bot_status' => 'UNKNOWN']];
        yield 'last seen before first seen' => [['last_seen_at' => '2026-09-04T09:59:59+00:00']];
        yield 'blocked status without blocked time' => [['bot_status' => 'BOT_BLOCKED']];
        yield 'anonymized profile retains snapshot' => [['bot_status' => 'ANONYMIZED']];
        yield 'negative exhausted sequence' => [['catalog_exhausted_through_sequence_no' => -1]];
        yield 'negative notified sequence' => [['catalog_notified_through_sequence_no' => -1]];
        yield 'negative lock version' => [['lock_version' => -1]];
    }

    public function testEnforcesOneProfilePerUserIdentity(): void
    {
        $this->insertCoreIdentity();
        $this->insertProfile();

        $this->expectException(IntegrityException::class);

        $this->insertProfile(['id' => self::SECOND_PROFILE_ID]);
    }

    public function testRestrictsDeletingLinkedUserIdentity(): void
    {
        $this->insertCoreIdentity();
        $this->insertProfile();

        $this->expectException(IntegrityException::class);

        $this->db->createCommand()
            ->delete('{{%user_identity}}', ['id' => self::USER_IDENTITY_ID])
            ->execute();
    }

    private function columnType(string $column): string|false
    {
        return $this->db->createCommand(
            <<<'SQL'
SELECT data_type
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'telegram_identity_profiles'
  AND column_name = :column
SQL,
            [':column' => $column],
        )->queryScalar();
    }

    private function columnLength(string $column): int|false
    {
        $length = $this->db->createCommand(
            <<<'SQL'
SELECT character_maximum_length
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'telegram_identity_profiles'
  AND column_name = :column
SQL,
            [':column' => $column],
        )->queryScalar();

        return $length === false ? false : (int) $length;
    }

    /**
     * @return array<string, array{type: string, update_action: string, delete_action: string}>
     */
    private function constraints(): array
    {
        $rows = $this->db->createCommand(
            <<<'SQL'
SELECT
    constraint_name.conname AS name,
    constraint_name.contype AS type,
    constraint_name.confupdtype AS update_action,
    constraint_name.confdeltype AS delete_action
FROM pg_constraint AS constraint_name
INNER JOIN pg_class AS table_name ON table_name.oid = constraint_name.conrelid
INNER JOIN pg_namespace AS table_schema ON table_schema.oid = table_name.relnamespace
WHERE table_schema.nspname = current_schema()
  AND table_name.relname = 'telegram_identity_profiles'
SQL,
        )->queryAll();

        $constraints = [];
        foreach ($rows as $row) {
            $constraints[(string) $row['name']] = [
                'type' => (string) $row['type'],
                'update_action' => (string) $row['update_action'],
                'delete_action' => (string) $row['delete_action'],
            ];
        }

        return $constraints;
    }

    /**
     * @return list<string>
     */
    private static function checkConstraintNames(): array
    {
        return [
            'chk_telegram_identity_profiles_bot_status',
            'chk_telegram_identity_profiles_seen_order',
            'chk_telegram_identity_profiles_blocked_state',
            'chk_telegram_identity_profiles_anonymized_snapshot',
            'chk_telegram_identity_profiles_catalog_exhausted_sequence',
            'chk_telegram_identity_profiles_catalog_notified_sequence',
            'chk_telegram_identity_profiles_lock_version',
        ];
    }

    private function insertCoreIdentity(): void
    {
        $this->db->createCommand()->insert('{{%user}}', [
            'id' => self::USER_ID,
            'surname' => 'Integration',
            'name' => 'Test',
            'auth_key' => '0123456789abcdef0123456789abcdef',
        ])->execute();

        $this->db->createCommand()->insert('{{%user_identity}}', [
            'id' => self::USER_IDENTITY_ID,
            'user_id' => self::USER_ID,
            'provider' => 'telegram',
            'provider_client_id' => '1000000000000000001',
        ])->execute();
    }

    /**
     * @param array<string, int|string|null> $overrides
     */
    private function insertProfile(array $overrides = []): void
    {
        $row = [
            'id' => self::PROFILE_ID,
            'user_identity_id' => self::USER_IDENTITY_ID,
            'username' => 'integration_user',
            'first_name' => 'Integration',
            'last_name' => 'Test',
            'language_code' => 'en',
            'bot_status' => 'ACTIVE',
            'catalog_exhausted_through_sequence_no' => null,
            'catalog_exhausted_at' => null,
            'catalog_notified_through_sequence_no' => null,
            'first_seen_at' => '2026-09-04T10:00:00+00:00',
            'last_seen_at' => '2026-09-04T10:00:00+00:00',
            'blocked_at' => null,
        ];

        $this->db->createCommand()
            ->insert('{{%telegram_identity_profiles}}', array_replace($row, $overrides))
            ->execute();
    }
}
