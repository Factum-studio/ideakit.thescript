<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use Yii;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\Transaction;

final class TelegramBotSessionSchemaTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab0';
    private const IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab1';
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab2';
    private const SESSION_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab3';

    private Connection $db;
    private ?Transaction $transaction = null;

    protected function _before(): void
    {
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertContains(
            $db->createCommand('SELECT current_database()')->queryScalar(),
            ['ideakit_test', 'ideakit_tg29_1_test'],
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
            'first_seen_at' => '2026-09-13T09:00:00+00:00',
            'last_seen_at' => '2026-09-13T09:00:00+00:00',
        ])->execute();
    }

    protected function _after(): void
    {
        if ($this->transaction !== null && $this->transaction->getIsActive()) {
            $this->transaction->rollBack();
        }
    }

    public function testDefinesApprovedStructureAndIndexes(): void
    {
        $rows = $this->db->createCommand(
            <<<'SQL'
SELECT column_name, udt_name, is_nullable, character_maximum_length, column_default
FROM information_schema.columns
WHERE table_schema = current_schema() AND table_name = :table
ORDER BY ordinal_position
SQL,
            [':table' => 'telegram_bot_sessions'],
        )->queryAll();
        $expected = [
            'id' => ['uuid', 'NO', null],
            'telegram_identity_profile_id' => ['uuid', 'NO', null],
            'bot_key' => ['varchar', 'NO', 64],
            'chat_id' => ['int8', 'NO', null],
            'flow_code' => ['varchar', 'NO', 32],
            'content_mode' => ['varchar', 'NO', 16],
            'content_subject_id' => ['uuid', 'YES', null],
            'agency_lead_id' => ['uuid', 'YES', null],
            'draft_comment' => ['text', 'YES', null],
            'expires_at' => ['timestamptz', 'YES', null],
            'interaction_revision' => ['int8', 'NO', null],
            'lock_version' => ['int8', 'NO', null],
            'created_at' => ['timestamptz', 'NO', null],
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
            if (in_array($column, ['interaction_revision', 'lock_version'], true)) {
                self::assertSame('0', $row['column_default'], $column);
            } elseif (in_array($column, ['created_at', 'updated_at'], true)) {
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
            [':table' => 'telegram_bot_sessions'],
        )->queryAll();
        $actual = array_column($constraints, null, 'conname');
        self::assertCount(8, $actual);
        self::assertSame('p', $actual['pk_telegram_bot_sessions']['contype']);
        self::assertSame('PRIMARY KEY (id)', $actual['pk_telegram_bot_sessions']['definition']);
        self::assertSame('u', $actual['uq_telegram_bot_sessions_bot_key_chat_id']['contype']);
        self::assertSame(
            'UNIQUE (bot_key, chat_id)',
            $actual['uq_telegram_bot_sessions_bot_key_chat_id']['definition'],
        );
        $foreignKey = $actual['fk_telegram_bot_sessions_profile_id'];
        self::assertSame('f', $foreignKey['contype']);
        self::assertSame('r', $foreignKey['confdeltype']);
        self::assertSame('r', $foreignKey['confupdtype']);
        self::assertStringContainsString(
            'FOREIGN KEY (telegram_identity_profile_id) REFERENCES telegram_identity_profiles(id)',
            $foreignKey['definition'],
        );
        foreach (['flow_code', 'content_mode', 'interaction_revision', 'lock_version', 'draft_state'] as $suffix) {
            self::assertSame('c', $actual['chk_telegram_bot_sessions_' . $suffix]['contype']);
        }
        $indexes = $this->db->createCommand(
            'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = :table',
            [':table' => 'telegram_bot_sessions'],
        )->queryColumn();
        sort($indexes, SORT_STRING);
        self::assertSame(['pk_telegram_bot_sessions', 'uq_telegram_bot_sessions_bot_key_chat_id'], $indexes);
    }

    public function testAcceptsDeclaredStatesAndTechnicalDefaults(): void
    {
        $this->insertSession();
        $row = $this->db->createCommand(
            'SELECT * FROM {{%telegram_bot_sessions}} WHERE id = :id',
            [':id' => self::SESSION_ID],
        )->queryOne();
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['interaction_revision']);
        self::assertSame(0, (int) $row['lock_version']);
        self::assertNotEmpty($row['created_at']);
        self::assertNotEmpty($row['updated_at']);
        foreach ([
            ['flow_code' => 'AWAITING_LEAD_COMMENT'],
            [
                'flow_code' => 'AWAITING_LEAD_CONFIRMATION',
                'draft_comment' => 'Synthetic launch request',
                'expires_at' => '2026-09-14T09:00:00+00:00',
            ],
            [
                'flow_code' => 'LEAD_REPLY',
                'content_mode' => 'CATALOG',
                'content_subject_id' => '01890f4d-3c2a-7f48-8c0b-123456789ab4',
                'agency_lead_id' => '01890f4d-3c2a-7f48-8c0b-123456789ab5',
                'draft_comment' => null,
                'expires_at' => null,
            ],
        ] as $values) {
            self::assertSame(1, $this->db->createCommand()->update(
                '{{%telegram_bot_sessions}}',
                $values,
                ['id' => self::SESSION_ID],
            )->execute());
        }
    }

    public function testEnforcesUniquenessOnlyForBotAndChat(): void
    {
        $this->insertSession();
        $this->insertSession([
            'id' => '01890f4d-3c2a-7f48-8c0b-123456789ab4',
            'bot_key' => 'secondary-test-bot',
        ]);
        $this->insertSession([
            'id' => '01890f4d-3c2a-7f48-8c0b-123456789ab5',
            'chat_id' => 1000000000000000002,
        ]);
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('uq_telegram_bot_sessions_bot_key_chat_id');
        $this->insertSession(['id' => '01890f4d-3c2a-7f48-8c0b-123456789ab6']);
    }

    public function testRestrictsDeletingLinkedProfile(): void
    {
        $this->insertSession();
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('fk_telegram_bot_sessions_profile_id');
        $this->db->createCommand()->delete(
            '{{%telegram_identity_profiles}}',
            ['id' => self::PROFILE_ID],
        )->execute();
    }

    /**
     * @dataProvider invalidRows
     *
     * @param array<string, int|string|null> $values
     */
    public function testRejectsInvalidRows(array $values, string $constraint): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage($constraint);
        $this->insertSession($values);
    }

    /**
     * @return iterable<string, array{array<string, int|string|null>, string}>
     */
    public static function invalidRows(): iterable
    {
        yield 'unknown flow' => [['flow_code' => 'UNKNOWN'], 'chk_telegram_bot_sessions_flow_code'];
        yield 'idle is absent' => [['flow_code' => 'IDLE'], 'chk_telegram_bot_sessions_flow_code'];
        yield 'unknown mode' => [['content_mode' => 'UNKNOWN'], 'chk_telegram_bot_sessions_content_mode'];
        yield 'negative interaction revision' => [
            ['interaction_revision' => -1], 'chk_telegram_bot_sessions_interaction_revision',
        ];
        yield 'negative lock version' => [['lock_version' => -1], 'chk_telegram_bot_sessions_lock_version'];
        yield 'confirmation without draft' => [
            ['flow_code' => 'AWAITING_LEAD_CONFIRMATION'], 'chk_telegram_bot_sessions_draft_state',
        ];
        yield 'confirmation without expiry' => [
            ['flow_code' => 'AWAITING_LEAD_CONFIRMATION', 'draft_comment' => 'Synthetic request'],
            'chk_telegram_bot_sessions_draft_state',
        ];
        yield 'confirmation without comment' => [
            ['flow_code' => 'AWAITING_LEAD_CONFIRMATION', 'expires_at' => '2026-09-14T09:00:00+00:00'],
            'chk_telegram_bot_sessions_draft_state',
        ];
        yield 'comment outside confirmation' => [
            ['draft_comment' => 'Synthetic request'], 'chk_telegram_bot_sessions_draft_state',
        ];
        yield 'expiry outside confirmation' => [
            ['expires_at' => '2026-09-14T09:00:00+00:00'], 'chk_telegram_bot_sessions_draft_state',
        ];
        yield 'draft outside confirmation' => [
            ['draft_comment' => 'Synthetic request', 'expires_at' => '2026-09-14T09:00:00+00:00'],
            'chk_telegram_bot_sessions_draft_state',
        ];
        yield 'missing profile' => [
            ['telegram_identity_profile_id' => '01890f4d-3c2a-7f48-8c0b-123456789ab9'],
            'fk_telegram_bot_sessions_profile_id',
        ];
    }

    /**
     * @param array<string, int|string|null> $values
     */
    private function insertSession(array $values = []): void
    {
        $this->db->createCommand()->insert('{{%telegram_bot_sessions}}', array_replace([
            'id' => self::SESSION_ID,
            'telegram_identity_profile_id' => self::PROFILE_ID,
            'bot_key' => 'primary-test-bot',
            'chat_id' => 1000000000000000001,
            'flow_code' => 'BROWSING',
            'content_mode' => 'DEMO',
        ], $values))->execute();
    }
}
