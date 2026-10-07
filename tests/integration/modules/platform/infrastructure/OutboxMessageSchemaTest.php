<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use Yii;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\JsonExpression;
use yii\db\Transaction;

final class OutboxMessageSchemaTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac1';
    private const IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac2';
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac3';
    private const CREATED_AT = '2026-09-26T09:00:00+00:00';
    private const DELIVERED_AT = '2026-09-26T09:05:00+00:00';

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

    public function testDefinesApprovedStructureAndIndexes(): void
    {
        $rows = $this->db->createCommand(
            <<<'SQL'
SELECT column_name, udt_name, is_nullable, character_maximum_length, column_default
FROM information_schema.columns
WHERE table_schema = current_schema() AND table_name = :table
ORDER BY ordinal_position
SQL,
            [':table' => 'outbox_messages'],
        )->queryAll();
        $expected = [
            'id' => ['uuid', 'NO', null],
            'owner_module' => ['varchar', 'NO', 32],
            'destination' => ['varchar', 'NO', 16],
            'routing_key' => ['varchar', 'NO', 64],
            'message_type' => ['varchar', 'NO', 64],
            'schema_version' => ['varchar', 'NO', 48],
            'aggregate_type' => ['varchar', 'NO', 32],
            'aggregate_id' => ['uuid', 'NO', null],
            'recipient_user_id' => ['uuid', 'YES', null],
            'telegram_identity_profile_id' => ['uuid', 'YES', null],
            'chat_id' => ['int8', 'YES', null],
            'idea_publication_id' => ['uuid', 'YES', null],
            'idea_version_id' => ['uuid', 'YES', null],
            'payload' => ['jsonb', 'YES', null],
            'payload_hash' => ['bpchar', 'NO', 64],
            'idempotency_key' => ['varchar', 'NO', 200],
            'status' => ['varchar', 'NO', 24],
            'attempt_count' => ['int2', 'NO', null],
            'attempt_history' => ['jsonb', 'NO', null],
            'next_attempt_at' => ['timestamptz', 'YES', null],
            'locked_by' => ['varchar', 'YES', 128],
            'locked_until' => ['timestamptz', 'YES', null],
            'external_reference' => ['varchar', 'YES', 255],
            'last_error_code' => ['varchar', 'YES', 64],
            'correlation_id' => ['uuid', 'NO', null],
            'created_at' => ['timestamptz', 'NO', null],
            'delivered_at' => ['timestamptz', 'YES', null],
            'payload_expires_at' => ['timestamptz', 'YES', null],
            'updated_at' => ['timestamptz', 'NO', null],
        ];
        self::assertCount(29, $rows);
        self::assertSame(array_keys($expected), array_column($rows, 'column_name'));
        foreach ($rows as $row) {
            $name = $row['column_name'];
            $length = $row['character_maximum_length'];
            self::assertSame(
                $expected[$name],
                [$row['udt_name'], $row['is_nullable'], $length === null ? null : (int) $length],
                $name,
            );
            $default = match ($name) {
                'attempt_count' => '0',
                'created_at', 'updated_at' => 'CURRENT_TIMESTAMP',
                default => null,
            };
            self::assertSame($default, $row['column_default'], $name);
        }

        $constraints = $this->db->createCommand(
            <<<'SQL'
SELECT conname, contype, confdeltype, confupdtype, pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = CAST(:table AS regclass)
ORDER BY conname
SQL,
            [':table' => 'outbox_messages'],
        )->queryAll();
        $actual = array_column($constraints, null, 'conname');
        $checks = [
            'aggregate_type_not_blank', 'attempt_count', 'attempt_history', 'destination',
            'idea_card_recipient', 'idempotency_key_not_blank', 'lease_state',
            'message_type_not_blank', 'owner_module_not_blank', 'payload_hash',
            'payload_retention', 'payload_state', 'retry_state', 'routing_key_not_blank',
            'schema_version_not_blank', 'status', 'telegram_unknown', 'terminal_state',
        ];
        $names = array_merge(
            array_map(static fn (string $name): string => 'chk_outbox_messages_' . $name, $checks),
            [
                'fk_outbox_messages_recipient_user_id',
                'fk_outbox_messages_telegram_identity_profile_id',
                'pk_outbox_messages',
                'uq_outbox_messages_idempotency_key',
            ],
        );
        sort($names, SORT_STRING);
        self::assertSame($names, array_keys($actual));
        foreach ($checks as $name) {
            self::assertSame('c', $actual['chk_outbox_messages_' . $name]['contype']);
        }
        self::assertSame('PRIMARY KEY (id)', $actual['pk_outbox_messages']['definition']);
        self::assertSame('UNIQUE (idempotency_key)', $actual['uq_outbox_messages_idempotency_key']['definition']);
        foreach ([
            'fk_outbox_messages_recipient_user_id' => 'recipient_user_id) REFERENCES "user"(id)',
            'fk_outbox_messages_telegram_identity_profile_id' =>
                'telegram_identity_profile_id) REFERENCES telegram_identity_profiles(id)',
        ] as $name => $reference) {
            self::assertSame('f', $actual[$name]['contype']);
            self::assertSame('r', $actual[$name]['confdeltype']);
            self::assertSame('r', $actual[$name]['confupdtype']);
            self::assertStringContainsString($reference, $actual[$name]['definition']);
        }

        $rows = $this->db->createCommand(
            <<<'SQL'
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = current_schema() AND tablename = :table
ORDER BY indexname
SQL,
            [':table' => 'outbox_messages'],
        )->queryAll();
        $indexes = array_column($rows, 'indexdef', 'indexname');
        self::assertSame([
            'idx_outbox_messages_aggregate_created',
            'idx_outbox_messages_payload_expires_at',
            'idx_outbox_messages_status_locked_until',
            'idx_outbox_messages_status_next_attempt_created',
            'pk_outbox_messages',
            'uq_outbox_messages_active_idea_card_recipient',
            'uq_outbox_messages_idempotency_key',
        ], array_keys($indexes));
        self::assertStringContainsString(
            '(aggregate_type, aggregate_id, created_at)',
            $indexes['idx_outbox_messages_aggregate_created'],
        );
        self::assertStringContainsString(
            '(status, next_attempt_at, created_at)',
            $indexes['idx_outbox_messages_status_next_attempt_created'],
        );
        self::assertStringContainsString('(status, locked_until)', $indexes['idx_outbox_messages_status_locked_until']);
        self::assertStringContainsString(
            'WHERE (payload IS NOT NULL)',
            $indexes['idx_outbox_messages_payload_expires_at'],
        );
        self::assertStringContainsString(
            '(recipient_user_id)',
            $indexes['uq_outbox_messages_active_idea_card_recipient'],
        );
        self::assertStringContainsString(
            "message_type)::text = 'IDEA_CARD'::text",
            $indexes['uq_outbox_messages_active_idea_card_recipient'],
        );
        foreach (['PENDING', 'PROCESSING', 'RETRY_SCHEDULED', 'UNKNOWN'] as $status) {
            self::assertStringContainsString(
                "'" . $status . "'",
                $indexes['uq_outbox_messages_active_idea_card_recipient'],
            );
        }
    }

    public function testAcceptsApprovedDestinationsAndStatuses(): void
    {
        foreach ([
            ['RABBITMQ', 'PENDING', []],
            ['RABBITMQ', 'PROCESSING', [
                'locked_by' => 'relay-1', 'locked_until' => '2026-09-26T09:10:00+00:00',
            ]],
            ['RABBITMQ', 'RETRY_SCHEDULED', ['next_attempt_at' => '2026-09-26T09:10:00+00:00']],
            ['RABBITMQ', 'DELIVERED', [
                'delivered_at' => self::DELIVERED_AT,
                'payload_expires_at' => '2026-10-26T09:05:00+00:00',
            ]],
            ['RABBITMQ', 'FAILED', []],
            ['TELEGRAM', 'UNKNOWN', []],
        ] as $index => [$destination, $status, $values]) {
            $this->insertMessage(array_merge($values, [
                'id' => self::messageId($index),
                'idempotency_key' => 'outbox-' . $index,
                'destination' => $destination,
                'status' => $status,
            ]));
        }
        self::assertSame(6, (int) $this->db->createCommand(
            'SELECT count(*) FROM {{%outbox_messages}}',
        )->queryScalar());
    }

    public function testAllowsTerminalCleanupAtThirtyDayBoundary(): void
    {
        $this->insertMessage([
            'status' => 'DELIVERED',
            'payload' => null,
            'delivered_at' => self::DELIVERED_AT,
            'payload_expires_at' => '2026-10-26T09:05:00+00:00',
        ]);
        $this->insertMessage([
            'id' => self::messageId(2),
            'idempotency_key' => 'outbox-2',
            'status' => 'FAILED',
            'payload_expires_at' => null,
        ]);
    }

    public function testEnforcesIdempotencyKeyUniqueness(): void
    {
        $this->insertMessage();
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('uq_outbox_messages_idempotency_key');
        $this->insertMessage(['id' => self::messageId(2)]);
    }

    public function testAllowsNextIdeaCardOnlyAfterDelivery(): void
    {
        $this->insertParents();
        $this->insertMessage(['recipient_user_id' => self::USER_ID, 'message_type' => 'IDEA_CARD']);
        self::assertSame(1, $this->db->createCommand()->update(
            '{{%outbox_messages}}',
            ['status' => 'DELIVERED', 'delivered_at' => self::DELIVERED_AT],
            ['id' => self::messageId(1)],
        )->execute());
        $this->insertMessage([
            'id' => self::messageId(2),
            'idempotency_key' => 'outbox-2',
            'recipient_user_id' => self::USER_ID,
            'message_type' => 'IDEA_CARD',
        ]);
        $this->insertMessage([
            'id' => self::messageId(3),
            'idempotency_key' => 'outbox-3',
            'recipient_user_id' => self::USER_ID,
            'message_type' => 'LEAD_MESSAGE',
        ]);
    }

    public function testRejectsSecondActiveIdeaCard(): void
    {
        $this->insertParents();
        $this->insertMessage(['recipient_user_id' => self::USER_ID, 'message_type' => 'IDEA_CARD']);
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage('uq_outbox_messages_active_idea_card_recipient');
        $this->insertMessage([
            'id' => self::messageId(2),
            'idempotency_key' => 'outbox-2',
            'recipient_user_id' => self::USER_ID,
            'message_type' => 'IDEA_CARD',
        ]);
    }

    /** @dataProvider invalidReferences */
    public function testRejectsUnknownParent(string $column, string $constraint): void
    {
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage($constraint);
        $this->insertMessage([$column => self::PROFILE_ID]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidReferences(): iterable
    {
        yield 'user' => ['recipient_user_id', 'fk_outbox_messages_recipient_user_id'];
        yield 'profile' => [
            'telegram_identity_profile_id', 'fk_outbox_messages_telegram_identity_profile_id',
        ];
    }

    /** @dataProvider restrictedParents */
    public function testRestrictsDeletingReferencedParent(string $table, string $id, string $constraint): void
    {
        $this->insertParents();
        $this->insertMessage([
            'recipient_user_id' => self::USER_ID,
            'telegram_identity_profile_id' => self::PROFILE_ID,
        ]);
        $this->expectException(IntegrityException::class);
        $this->expectExceptionMessage($constraint);
        $this->db->createCommand()->delete($table, ['id' => $id])->execute();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function restrictedParents(): iterable
    {
        yield 'user' => ['{{%user}}', self::USER_ID, 'fk_outbox_messages_recipient_user_id'];
        yield 'profile' => [
            '{{%telegram_identity_profiles}}', self::PROFILE_ID,
            'fk_outbox_messages_telegram_identity_profile_id',
        ];
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
        $this->insertMessage($values);
    }

    /** @return iterable<string, array{array<string, int|string|JsonExpression|null>, string}> */
    public static function invalidRows(): iterable
    {
        yield 'destination' => [['destination' => 'OTHER'], 'chk_outbox_messages_destination'];
        yield 'status' => [['status' => 'OTHER'], 'chk_outbox_messages_status'];
        yield 'attempt count' => [['attempt_count' => -1], 'chk_outbox_messages_attempt_count'];
        foreach ([
            'owner_module', 'routing_key', 'message_type', 'schema_version',
            'aggregate_type', 'idempotency_key',
        ] as $column) {
            yield $column . ' blank' => [[$column => '   '], 'chk_outbox_messages_' . $column . '_not_blank'];
        }
        yield 'hash' => [['payload_hash' => str_repeat('A', 64)], 'chk_outbox_messages_payload_hash'];
        yield 'payload array' => [[
            'payload' => new JsonExpression([], 'jsonb'),
        ], 'chk_outbox_messages_payload_state'];
        yield 'history array' => [[
            'attempt_history' => new JsonExpression([], 'jsonb'),
        ], 'chk_outbox_messages_attempt_history'];
        yield 'history without version' => [[
            'attempt_history' => new JsonExpression(['attempts' => []], 'jsonb'),
        ], 'chk_outbox_messages_attempt_history'];
        yield 'history attempts not array' => [[
            'attempt_history' => new JsonExpression(['schema_version' => '1.0', 'attempts' => 'invalid'], 'jsonb'),
        ], 'chk_outbox_messages_attempt_history'];
        yield 'telegram history attempts not array' => [[
            'destination' => 'TELEGRAM',
            'attempt_history' => new JsonExpression(['schema_version' => '1.0', 'attempts' => 'invalid'], 'jsonb'),
        ], 'chk_outbox_messages_attempt_history'];
        yield 'telegram history over five' => [[
            'destination' => 'TELEGRAM',
            'attempt_history' => new JsonExpression([
                'schema_version' => '1.0', 'attempts' => [1, 2, 3, 4, 5, 6],
            ], 'jsonb'),
        ], 'chk_outbox_messages_attempt_history'];
        yield 'lease absent' => [['status' => 'PROCESSING'], 'chk_outbox_messages_lease_state'];
        yield 'lease partial' => [[
            'status' => 'PROCESSING', 'locked_by' => 'relay-1',
        ], 'chk_outbox_messages_lease_state'];
        yield 'lease blank owner' => [[
            'status' => 'PROCESSING', 'locked_by' => '   ',
            'locked_until' => '2026-09-26T09:10:00+00:00',
        ], 'chk_outbox_messages_lease_state'];
        yield 'lease outside processing' => [[
            'locked_by' => 'relay-1', 'locked_until' => '2026-09-26T09:10:00+00:00',
        ], 'chk_outbox_messages_lease_state'];
        yield 'retry without time' => [[
            'status' => 'RETRY_SCHEDULED',
        ], 'chk_outbox_messages_retry_state'];
        yield 'retry time outside retry' => [[
            'next_attempt_at' => '2026-09-26T09:10:00+00:00',
        ], 'chk_outbox_messages_retry_state'];
        yield 'rabbitmq unknown' => [[
            'status' => 'UNKNOWN',
        ], 'chk_outbox_messages_telegram_unknown'];
        yield 'delivery without time' => [[
            'status' => 'DELIVERED',
        ], 'chk_outbox_messages_terminal_state'];
        yield 'delivery time outside delivered' => [[
            'delivered_at' => self::DELIVERED_AT,
        ], 'chk_outbox_messages_terminal_state'];
        yield 'missing active payload' => [[
            'payload' => null,
        ], 'chk_outbox_messages_payload_state'];
        yield 'idea card without recipient' => [[
            'message_type' => 'IDEA_CARD',
        ], 'chk_outbox_messages_idea_card_recipient'];
        yield 'active payload expiry' => [[
            'payload_expires_at' => '2026-10-26T09:05:00+00:00',
        ], 'chk_outbox_messages_payload_retention'];
        yield 'unknown payload expiry' => [[
            'destination' => 'TELEGRAM', 'status' => 'UNKNOWN',
            'payload_expires_at' => '2026-10-26T09:05:00+00:00',
        ], 'chk_outbox_messages_payload_retention'];
        yield 'early delivered payload expiry' => [[
            'status' => 'DELIVERED', 'delivered_at' => self::DELIVERED_AT,
            'payload_expires_at' => '2026-10-26T09:04:59+00:00',
        ], 'chk_outbox_messages_payload_retention'];
    }

    /** @param array<string, int|string|JsonExpression|null> $values */
    private function insertMessage(array $values = []): void
    {
        $this->db->createCommand()->insert('{{%outbox_messages}}', array_replace([
            'id' => self::messageId(1),
            'owner_module' => 'Telegram',
            'destination' => 'RABBITMQ',
            'routing_key' => 'telegram.update.received/1.0',
            'message_type' => 'TELEGRAM_UPDATE_RECEIVED',
            'schema_version' => '1.0',
            'aggregate_type' => 'TELEGRAM_UPDATE',
            'aggregate_id' => '01890f4d-3c2a-7f48-8c0b-123456789ac4',
            'payload' => new JsonExpression(['update_id' => '01890f4d-3c2a-7f48-8c0b-123456789ac4'], 'jsonb'),
            'payload_hash' => str_repeat('a', 64),
            'idempotency_key' => 'outbox-1',
            'status' => 'PENDING',
            'attempt_history' => new JsonExpression(['schema_version' => '1.0', 'attempts' => []], 'jsonb'),
            'correlation_id' => '01890f4d-3c2a-7f48-8c0b-123456789ac5',
            'created_at' => self::CREATED_AT,
            'updated_at' => self::CREATED_AT,
        ], $values))->execute();
    }

    private function insertParents(): void
    {
        $this->db->createCommand()->insert('{{%user}}', [
            'id' => self::USER_ID,
            'name' => null,
            'surname' => null,
            'auth_key' => '0123456789abcdef0123456789abcdef',
        ])->execute();
        $this->db->createCommand()->insert('{{%user_identity}}', [
            'id' => self::IDENTITY_ID,
            'user_id' => self::USER_ID,
            'provider' => 'telegram',
            'provider_client_id' => '1000000000000000002',
        ])->execute();
        $this->db->createCommand()->insert('{{%telegram_identity_profiles}}', [
            'id' => self::PROFILE_ID,
            'user_identity_id' => self::IDENTITY_ID,
            'bot_status' => 'ACTIVE',
            'first_seen_at' => self::CREATED_AT,
            'last_seen_at' => self::CREATED_AT,
        ])->execute();
    }

    private static function messageId(int $suffix): string
    {
        return sprintf('01890f4d-3c2a-7f48-8c0b-%012d', 123456789000 + $suffix);
    }
}
