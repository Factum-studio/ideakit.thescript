<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\migrations;

use yii\db\Migration;

final class m260926_090000_create_outbox_messages_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%outbox_messages}}', [
            'id' => 'uuid NOT NULL',
            'owner_module' => 'varchar(32) NOT NULL',
            'destination' => 'varchar(16) NOT NULL',
            'routing_key' => 'varchar(64) NOT NULL',
            'message_type' => 'varchar(64) NOT NULL',
            'schema_version' => 'varchar(48) NOT NULL',
            'aggregate_type' => 'varchar(32) NOT NULL',
            'aggregate_id' => 'uuid NOT NULL',
            'recipient_user_id' => 'uuid NULL',
            'telegram_identity_profile_id' => 'uuid NULL',
            'chat_id' => 'bigint NULL',
            'idea_publication_id' => 'uuid NULL',
            'idea_version_id' => 'uuid NULL',
            'payload' => 'jsonb NULL',
            'payload_hash' => 'char(64) NOT NULL',
            'idempotency_key' => 'varchar(200) NOT NULL',
            'status' => 'varchar(24) NOT NULL',
            'attempt_count' => 'smallint NOT NULL DEFAULT 0',
            'attempt_history' => 'jsonb NOT NULL',
            'next_attempt_at' => 'timestamptz NULL',
            'locked_by' => 'varchar(128) NULL',
            'locked_until' => 'timestamptz NULL',
            'external_reference' => 'varchar(255) NULL',
            'last_error_code' => 'varchar(64) NULL',
            'correlation_id' => 'uuid NOT NULL',
            'created_at' => 'timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'delivered_at' => 'timestamptz NULL',
            'payload_expires_at' => 'timestamptz NULL',
            'updated_at' => 'timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);

        $this->addPrimaryKey('pk_outbox_messages', '{{%outbox_messages}}', 'id');
        $this->execute(
            'ALTER TABLE {{%outbox_messages}} '
            . 'ADD CONSTRAINT uq_outbox_messages_idempotency_key UNIQUE (idempotency_key)',
        );
        $this->addForeignKey(
            'fk_outbox_messages_recipient_user_id',
            '{{%outbox_messages}}',
            'recipient_user_id',
            '{{%user}}',
            'id',
            'RESTRICT',
            'RESTRICT',
        );
        $this->addForeignKey(
            'fk_outbox_messages_telegram_identity_profile_id',
            '{{%outbox_messages}}',
            'telegram_identity_profile_id',
            '{{%telegram_identity_profiles}}',
            'id',
            'RESTRICT',
            'RESTRICT',
        );

        $checks = [
            'chk_outbox_messages_owner_module_not_blank' => "btrim(owner_module) <> ''",
            'chk_outbox_messages_destination' => "destination IN ('RABBITMQ', 'TELEGRAM')",
            'chk_outbox_messages_routing_key_not_blank' => "btrim(routing_key) <> ''",
            'chk_outbox_messages_message_type_not_blank' => "btrim(message_type) <> ''",
            'chk_outbox_messages_schema_version_not_blank' => "btrim(schema_version) <> ''",
            'chk_outbox_messages_aggregate_type_not_blank' => "btrim(aggregate_type) <> ''",
            'chk_outbox_messages_idempotency_key_not_blank' => "btrim(idempotency_key) <> ''",
            'chk_outbox_messages_status' =>
                "status IN ('PENDING', 'PROCESSING', 'RETRY_SCHEDULED', 'DELIVERED', 'FAILED', 'UNKNOWN')",
            'chk_outbox_messages_attempt_count' => 'attempt_count >= 0',
            'chk_outbox_messages_payload_hash' => "payload_hash ~ '^[0-9a-f]{64}$'",
            'chk_outbox_messages_attempt_history' =>
                "jsonb_typeof(attempt_history) = 'object' "
                . "AND coalesce(jsonb_typeof(attempt_history->'schema_version') = 'string', false) "
                . "AND coalesce(btrim(attempt_history->>'schema_version') <> '', false) "
                . "AND coalesce(jsonb_typeof(attempt_history->'attempts') = 'array', false) "
                . "AND (destination <> 'TELEGRAM' "
                . "OR jsonb_array_length(attempt_history->'attempts') <= 5)",
            'chk_outbox_messages_lease_state' =>
                "(status = 'PROCESSING' AND locked_by IS NOT NULL AND btrim(locked_by) <> '' "
                . "AND locked_until IS NOT NULL) OR (status <> 'PROCESSING' "
                . 'AND locked_by IS NULL AND locked_until IS NULL)',
            'chk_outbox_messages_retry_state' =>
                "(status = 'RETRY_SCHEDULED' AND next_attempt_at IS NOT NULL) "
                . "OR (status <> 'RETRY_SCHEDULED' AND next_attempt_at IS NULL)",
            'chk_outbox_messages_terminal_state' =>
                "(status = 'DELIVERED' AND delivered_at IS NOT NULL) "
                . "OR (status <> 'DELIVERED' AND delivered_at IS NULL)",
            'chk_outbox_messages_telegram_unknown' =>
                "status <> 'UNKNOWN' OR destination = 'TELEGRAM'",
            'chk_outbox_messages_payload_state' =>
                "(payload IS NULL OR jsonb_typeof(payload) = 'object') "
                . "AND (status NOT IN ('PENDING', 'PROCESSING', 'RETRY_SCHEDULED', 'UNKNOWN') "
                . 'OR payload IS NOT NULL)',
            'chk_outbox_messages_idea_card_recipient' =>
                "message_type <> 'IDEA_CARD' OR recipient_user_id IS NOT NULL",
            'chk_outbox_messages_payload_retention' =>
                "status NOT IN ('PENDING', 'PROCESSING', 'RETRY_SCHEDULED', 'DELIVERED', 'FAILED', 'UNKNOWN') "
                . "OR (status IN ('PENDING', 'PROCESSING', 'RETRY_SCHEDULED', 'UNKNOWN') "
                . "AND payload_expires_at IS NULL) OR (status = 'DELIVERED' "
                . "AND (payload_expires_at IS NULL "
                . "OR payload_expires_at >= delivered_at + INTERVAL '30 days')) "
                . "OR status = 'FAILED'",
        ];
        foreach ($checks as $name => $expression) {
            $this->execute(
                'ALTER TABLE {{%outbox_messages}} ADD CONSTRAINT ' . $name . ' CHECK (' . $expression . ')',
            );
        }

        $this->createIndex(
            'idx_outbox_messages_status_next_attempt_created',
            '{{%outbox_messages}}',
            ['status', 'next_attempt_at', 'created_at'],
        );
        $this->createIndex(
            'idx_outbox_messages_status_locked_until',
            '{{%outbox_messages}}',
            ['status', 'locked_until'],
        );
        $this->createIndex(
            'idx_outbox_messages_aggregate_created',
            '{{%outbox_messages}}',
            ['aggregate_type', 'aggregate_id', 'created_at'],
        );
        $this->execute(
            'CREATE INDEX idx_outbox_messages_payload_expires_at '
            . 'ON {{%outbox_messages}} (payload_expires_at) WHERE payload IS NOT NULL',
        );
        $this->execute(
            'CREATE UNIQUE INDEX uq_outbox_messages_active_idea_card_recipient '
            . "ON {{%outbox_messages}} (recipient_user_id) WHERE message_type = 'IDEA_CARD' "
            . "AND status IN ('PENDING', 'PROCESSING', 'RETRY_SCHEDULED', 'UNKNOWN')",
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey(
            'fk_outbox_messages_telegram_identity_profile_id',
            '{{%outbox_messages}}',
        );
        $this->dropForeignKey(
            'fk_outbox_messages_recipient_user_id',
            '{{%outbox_messages}}',
        );
        $this->dropTable('{{%outbox_messages}}');
    }
}
