<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use Yii;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class TelegramUpdateSchemaTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab0';
    private const IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab1';
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab2';
    private const UPDATE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab3';
    private const RECEIVED_AT = '2026-09-13T09:00:00+00:00';
    private const PAYLOAD_EXPIRES_AT = '2026-09-14T09:00:00+00:00';

    private Connection $db;
    private ?Transaction $transaction = null;

    protected function _before(): void
    {
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertContains(
            $db->createCommand('SELECT current_database()')->queryScalar(),
            ['ideakit_test', 'ideakit_tg29_2_test'],
        );
        $this->db = $db;
        $this->transaction = $db->beginTransaction();
        $db->createCommand()->insert('{{%user}}', [
            'id' => self::USER_ID,
            'name' => null,
            'surname' => null,
            'auth_key' => '0123456789abcdef0123456789abcdef',
        ])->execute();
        $db->createCommand()->insert('{{%user_identity}}', [
            'id' => self::IDENTITY_ID,
            'user_id' => self::USER_ID,
            'provider' => 'telegram',
            'provider_client_id' => '1000000000000000001',
        ])->execute();
        $db->createCommand()->insert('{{%telegram_identity_profiles}}', [
            'id' => self::PROFILE_ID,
            'user_identity_id' => self::IDENTITY_ID,
            'bot_status' => 'ACTIVE',
            'first_seen_at' => self::RECEIVED_AT,
            'last_seen_at' => self::RECEIVED_AT,
        ])->execute();
    }

    protected function _after(): void
    {
        if ($this->transaction !== null && $this->transaction->getIsActive()) {
            $this->transaction->rollBack();
        }
    }

    public function testDefinesApprovedStructureConstraintsAndIndexes(): void
    {
        $rows = $this->db->createCommand(
            <<<'SQL'
SELECT column_name, udt_name, is_nullable, character_maximum_length, column_default
FROM information_schema.columns
WHERE table_schema = current_schema() AND table_name = :table
ORDER BY ordinal_position
SQL,
            [':table' => 'telegram_updates'],
        )->queryAll();
        $expected = [
            'id' => ['uuid', 'NO', null],
            'bot_key' => ['varchar', 'NO', 64],
            'update_id' => ['int8', 'NO', null],
            'telegram_identity_profile_id' => ['uuid', 'YES', null],
            'chat_id' => ['int8', 'YES', null],
            'update_type' => ['varchar', 'NO', 32],
            'payload_schema_version' => ['varchar', 'NO', 32],
            'payload_hash' => ['bpchar', 'NO', 64],
            'raw_payload' => ['jsonb', 'YES', null],
            'status' => ['varchar', 'NO', 24],
            'attempt_count' => ['int2', 'NO', null],
            'next_attempt_at' => ['timestamptz', 'YES', null],
            'last_error_code' => ['varchar', 'YES', 64],
            'received_at' => ['timestamptz', 'NO', null],
            'processed_at' => ['timestamptz', 'YES', null],
            'raw_payload_expires_at' => ['timestamptz', 'NO', null],
            'locked_by' => ['varchar', 'YES', 128],
            'locked_until' => ['timestamptz', 'YES', null],
            'updated_at' => ['timestamptz', 'NO', null],
        ];
        self::assertSame(array_keys($expected), array_column($rows, 'column_name'));
        foreach ($rows as $row) {
            $column = $row['column_name'];
            $length = $row['character_maximum_length'];
            self::assertSame(
                $expected[$column],
                [$row['udt_name'], $row['is_nullable'], $length === null ? null : (int) $length],
                $column,
            );
            if ($column === 'attempt_count') {
                self::assertSame('0', $row['column_default'], $column);
            } elseif (in_array($column, ['received_at', 'updated_at'], true)) {
                self::assertSame('CURRENT_TIMESTAMP', $row['column_default'], $column);
            } else {
                self::assertNull($row['column_default'], $column);
            }
        }

        $constraints = $this->db->createCommand(
            <<<'SQL'
SELECT conname, contype, confdeltype, confupdtype, pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = CAST(:table AS regclass)
ORDER BY conname
SQL,
            [':table' => 'telegram_updates'],
        )->queryAll();
        $actual = array_column($constraints, null, 'conname');
        $expectedTypes = [
            'chk_telegram_updates_attempt_count' => 'c',
            'chk_telegram_updates_bot_key_not_blank' => 'c',
            'chk_telegram_updates_last_error_code' => 'c',
            'chk_telegram_updates_lease_state' => 'c',
            'chk_telegram_updates_payload_hash' => 'c',
            'chk_telegram_updates_payload_retention' => 'c',
            'chk_telegram_updates_payload_state' => 'c',
            'chk_telegram_updates_processed_state' => 'c',
            'chk_telegram_updates_raw_payload_type' => 'c',
            'chk_telegram_updates_retry_state' => 'c',
            'chk_telegram_updates_schema_version_not_blank' => 'c',
            'chk_telegram_updates_status' => 'c',
            'chk_telegram_updates_update_type' => 'c',
            'fk_telegram_updates_profile_id' => 'f',
            'pk_telegram_updates' => 'p',
            'uq_telegram_updates_bot_key_update_id' => 'u',
        ];
        self::assertSame(array_keys($expectedTypes), array_keys($actual));
        foreach ($expectedTypes as $name => $type) {
            self::assertSame($type, $actual[$name]['contype'], $name);
        }
        self::assertSame('PRIMARY KEY (id)', $actual['pk_telegram_updates']['definition']);
        self::assertSame(
            'UNIQUE (bot_key, update_id)',
            $actual['uq_telegram_updates_bot_key_update_id']['definition'],
        );
        $foreignKey = $actual['fk_telegram_updates_profile_id'];
        self::assertSame('r', $foreignKey['confdeltype']);
        self::assertSame('r', $foreignKey['confupdtype']);
        self::assertStringContainsString(
            'FOREIGN KEY (telegram_identity_profile_id) REFERENCES telegram_identity_profiles(id)',
            $foreignKey['definition'],
        );

        $indexRows = $this->db->createCommand(
            <<<'SQL'
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = current_schema() AND tablename = :table
ORDER BY indexname
SQL,
            [':table' => 'telegram_updates'],
        )->queryAll();
        $indexes = array_column($indexRows, 'indexdef', 'indexname');
        self::assertSame([
            'idx_telegram_updates_raw_payload_expires_at',
            'idx_telegram_updates_status_locked_until',
            'idx_telegram_updates_status_next_attempt_received',
            'pk_telegram_updates',
            'uq_telegram_updates_bot_key_update_id',
        ], array_keys($indexes));
        self::assertStringContainsString(
            'WHERE (raw_payload IS NOT NULL)',
            $indexes['idx_telegram_updates_raw_payload_expires_at'],
        );
    }

    public function testAppliesDefaultsAndAllowsMissingProfileAndChat(): void
    {
        $this->insertUpdate([
            'telegram_identity_profile_id' => null,
            'chat_id' => null,
        ]);
        $row = $this->db->createCommand(
            'SELECT * FROM {{%telegram_updates}} WHERE id = :id',
            [':id' => self::UPDATE_ID],
        )->queryOne();
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['attempt_count']);
        self::assertNull($row['telegram_identity_profile_id']);
        self::assertNull($row['chat_id']);
        self::assertSame(
            ['update_id' => 1000000000000000001],
            json_decode((string) $row['raw_payload'], true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertNotEmpty($row['received_at']);
        self::assertNotEmpty($row['updated_at']);
    }

    public function testAcceptsApprovedStateMatrix(): void
    {
        $this->insertUpdate();
        foreach ([
            [
                'status' => 'PROCESSING',
                'locked_by' => 'worker-1',
                'locked_until' => '2026-09-13T09:05:00+00:00',
            ],
            [
                'status' => 'RETRY_SCHEDULED',
                'locked_by' => null,
                'locked_until' => null,
                'next_attempt_at' => '2026-09-13T09:10:00+00:00',
                'last_error_code' => 'TEMPORARY_FAILURE',
            ],
            [
                'status' => 'PROCESSING',
                'next_attempt_at' => null,
                'locked_by' => 'worker-2',
                'locked_until' => '2026-09-13T09:15:00+00:00',
            ],
            [
                'status' => 'PROCESSED',
                'raw_payload' => null,
                'locked_by' => null,
                'locked_until' => null,
                'processed_at' => '2026-09-13T09:20:00+00:00',
            ],
            [
                'status' => 'FAILED',
                'last_error_code' => 'PERMANENT_FAILURE',
            ],
            [
                'status' => 'IGNORED',
                'update_type' => 'UNSUPPORTED',
                'last_error_code' => null,
            ],
        ] as $values) {
            self::assertSame(1, $this->db->createCommand()->update(
                '{{%telegram_updates}}',
                $values,
                ['id' => self::UPDATE_ID],
            )->execute());
        }
    }

    public function testAcceptsApprovedUpdateTypes(): void
    {
        $this->insertUpdate();
        foreach (['MESSAGE', 'CALLBACK_QUERY', 'MY_CHAT_MEMBER', 'UNSUPPORTED'] as $type) {
            self::assertSame(1, $this->db->createCommand()->update(
                '{{%telegram_updates}}',
                ['update_type' => $type],
                ['id' => self::UPDATE_ID],
            )->execute());
        }
    }

    public function testAcceptsPayloadRetentionBoundaries(): void
    {
        $this->insertUpdate(['raw_payload_expires_at' => self::RECEIVED_AT]);
        self::assertSame(1, $this->db->createCommand()->update(
            '{{%telegram_updates}}',
            ['raw_payload_expires_at' => '2026-10-13T09:00:00+00:00'],
            ['id' => self::UPDATE_ID],
        )->execute());
    }

    public function testEnforcesUniquenessForBotAndUpdate(): void
    {
        $this->insertUpdate();
        $this->insertUpdate([
            'id' => '01890f4d-3c2a-7f48-8c0b-123456789ab4',
            'bot_key' => 'secondary-test-bot',
        ]);
        $this->insertUpdate([
            'id' => '01890f4d-3c2a-7f48-8c0b-123456789ab5',
            'update_id' => 1000000000000000002,
        ]);
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('uq_telegram_updates_bot_key_update_id');
        $this->insertUpdate(['id' => '01890f4d-3c2a-7f48-8c0b-123456789ab6']);
    }

    public function testRejectsUnknownProfile(): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('fk_telegram_updates_profile_id');
        $this->insertUpdate([
            'telegram_identity_profile_id' => '01890f4d-3c2a-7f48-8c0b-123456789ab9',
        ]);
    }

    public function testRestrictsDeletingLinkedProfile(): void
    {
        $this->insertUpdate(['telegram_identity_profile_id' => self::PROFILE_ID]);
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('fk_telegram_updates_profile_id');
        $this->db->createCommand()->delete(
            '{{%telegram_identity_profiles}}',
            ['id' => self::PROFILE_ID],
        )->execute();
    }

    /**
     * @dataProvider invalidRows
     *
     * @param array<string, int|string|JsonExpression|null> $values
     */
    public function testRejectsInvalidRows(array $values, string $constraint): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage($constraint);
        $this->insertUpdate($values);
    }

    /**
     * @return iterable<string, array{array<string, int|string|JsonExpression|null>, string}>
     */
    public static function invalidRows(): iterable
    {
        yield 'unknown update type' => [
            ['update_type' => 'UNKNOWN'], 'chk_telegram_updates_update_type',
        ];
        yield 'unknown status' => [['status' => 'UNKNOWN'], 'chk_telegram_updates_status'];
        yield 'negative attempt count' => [
            ['attempt_count' => -1], 'chk_telegram_updates_attempt_count',
        ];
        yield 'blank bot key' => [['bot_key' => '   '], 'chk_telegram_updates_bot_key_not_blank'];
        yield 'blank schema version' => [
            ['payload_schema_version' => '   '], 'chk_telegram_updates_schema_version_not_blank',
        ];
        yield 'blank optional error code' => [
            ['last_error_code' => '   '], 'chk_telegram_updates_last_error_code',
        ];
        yield 'retry without error code' => [[
            'status' => 'RETRY_SCHEDULED',
            'next_attempt_at' => '2026-09-13T09:10:00+00:00',
        ], 'chk_telegram_updates_last_error_code'];
        yield 'failed without error code' => [[
            'status' => 'FAILED',
            'raw_payload' => null,
            'processed_at' => '2026-09-13T09:10:00+00:00',
        ], 'chk_telegram_updates_last_error_code'];
        yield 'short payload hash' => [
            ['payload_hash' => 'abc'], 'chk_telegram_updates_payload_hash',
        ];
        yield 'uppercase payload hash' => [
            ['payload_hash' => str_repeat('A', 64)], 'chk_telegram_updates_payload_hash',
        ];
        yield 'non-hex payload hash' => [
            ['payload_hash' => str_repeat('g', 64)], 'chk_telegram_updates_payload_hash',
        ];
        yield 'raw payload is array' => [
            ['raw_payload' => new JsonExpression([], 'jsonb')], 'chk_telegram_updates_raw_payload_type',
        ];
        yield 'processing without lease' => [
            ['status' => 'PROCESSING'], 'chk_telegram_updates_lease_state',
        ];
        yield 'processing with worker only' => [[
            'status' => 'PROCESSING',
            'locked_by' => 'worker-1',
        ], 'chk_telegram_updates_lease_state'];
        yield 'processing with expiry only' => [[
            'status' => 'PROCESSING',
            'locked_until' => '2026-09-13T09:05:00+00:00',
        ], 'chk_telegram_updates_lease_state'];
        yield 'processing with blank worker' => [[
            'status' => 'PROCESSING',
            'locked_by' => '   ',
            'locked_until' => '2026-09-13T09:05:00+00:00',
        ], 'chk_telegram_updates_lease_state'];
        yield 'lease outside processing' => [[
            'locked_by' => 'worker-1',
            'locked_until' => '2026-09-13T09:05:00+00:00',
        ], 'chk_telegram_updates_lease_state'];
        yield 'retry without next attempt' => [[
            'status' => 'RETRY_SCHEDULED',
            'last_error_code' => 'TEMPORARY_FAILURE',
        ], 'chk_telegram_updates_retry_state'];
        yield 'next attempt outside retry' => [[
            'next_attempt_at' => '2026-09-13T09:10:00+00:00',
        ], 'chk_telegram_updates_retry_state'];
        yield 'terminal status without processed time' => [[
            'status' => 'PROCESSED',
            'raw_payload' => null,
        ], 'chk_telegram_updates_processed_state'];
        yield 'processed time in nonterminal status' => [[
            'processed_at' => '2026-09-13T09:10:00+00:00',
        ], 'chk_telegram_updates_processed_state'];
        yield 'nonterminal status without payload' => [[
            'raw_payload' => null,
        ], 'chk_telegram_updates_payload_state'];
        yield 'terminal status retains payload' => [[
            'status' => 'PROCESSED',
            'processed_at' => '2026-09-13T09:10:00+00:00',
        ], 'chk_telegram_updates_payload_state'];
        yield 'payload expiry before receipt' => [[
            'raw_payload_expires_at' => '2026-09-13T08:59:59+00:00',
        ], 'chk_telegram_updates_payload_retention'];
        yield 'payload expiry after thirty days' => [[
            'raw_payload_expires_at' => '2026-10-13T09:00:01+00:00',
        ], 'chk_telegram_updates_payload_retention'];
    }

    /**
     * @param array<string, int|string|JsonExpression|null> $values
     */
    private function insertUpdate(array $values = []): void
    {
        $this->db->createCommand()->insert('{{%telegram_updates}}', array_replace([
            'id' => self::UPDATE_ID,
            'bot_key' => 'primary-test-bot',
            'update_id' => 1000000000000000001,
            'telegram_identity_profile_id' => null,
            'chat_id' => 1000000000000000001,
            'update_type' => 'MESSAGE',
            'payload_schema_version' => 'telegram.update/1.0',
            'payload_hash' => str_repeat('a', 64),
            'raw_payload' => new JsonExpression(['update_id' => 1000000000000000001], 'jsonb'),
            'status' => 'RECEIVED',
            'received_at' => self::RECEIVED_AT,
            'raw_payload_expires_at' => self::PAYLOAD_EXPIRES_AT,
        ], $values))->execute();
    }
}
