<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\migrations;

use yii\db\Migration;

final class m260913_172000_create_telegram_updates_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%telegram_updates}}', [
            'id' => 'uuid NOT NULL',
            'bot_key' => $this->string(64)->notNull(),
            'update_id' => 'bigint NOT NULL',
            'telegram_identity_profile_id' => 'uuid NULL',
            'chat_id' => 'bigint NULL',
            'update_type' => $this->string(32)->notNull(),
            'payload_schema_version' => $this->string(32)->notNull(),
            'payload_hash' => 'char(64) NOT NULL',
            'raw_payload' => 'jsonb NULL',
            'status' => $this->string(24)->notNull(),
            'attempt_count' => 'smallint NOT NULL DEFAULT 0',
            'next_attempt_at' => 'timestamptz NULL',
            'last_error_code' => 'varchar(64) NULL',
            'received_at' => 'timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'processed_at' => 'timestamptz NULL',
            'raw_payload_expires_at' => 'timestamptz NOT NULL',
            'locked_by' => 'varchar(128) NULL',
            'locked_until' => 'timestamptz NULL',
            'updated_at' => 'timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->addPrimaryKey('pk_telegram_updates', '{{%telegram_updates}}', 'id');
        $this->execute(
            'ALTER TABLE {{%telegram_updates}} '
            . 'ADD CONSTRAINT uq_telegram_updates_bot_key_update_id UNIQUE (bot_key, update_id)',
        );
        $this->addForeignKey(
            'fk_telegram_updates_profile_id',
            '{{%telegram_updates}}',
            'telegram_identity_profile_id',
            '{{%telegram_identity_profiles}}',
            'id',
            'RESTRICT',
            'RESTRICT',
        );

        $knownStatuses = "'RECEIVED', 'PROCESSING', 'RETRY_SCHEDULED', 'PROCESSED', 'FAILED', 'IGNORED'";
        $checks = [
            'chk_telegram_updates_update_type' =>
                "update_type IN ('MESSAGE', 'CALLBACK_QUERY', 'MY_CHAT_MEMBER', 'UNSUPPORTED')",
            'chk_telegram_updates_status' => "status IN ($knownStatuses)",
            'chk_telegram_updates_attempt_count' => 'attempt_count >= 0',
            'chk_telegram_updates_bot_key_not_blank' => "btrim(bot_key) <> ''",
            'chk_telegram_updates_schema_version_not_blank' => "btrim(payload_schema_version) <> ''",
            'chk_telegram_updates_last_error_code' =>
                "(last_error_code IS NULL OR btrim(last_error_code) <> '') "
                . "AND (status NOT IN ('RETRY_SCHEDULED', 'FAILED') OR last_error_code IS NOT NULL)",
            'chk_telegram_updates_payload_hash' => "payload_hash ~ '^[0-9a-f]{64}$'",
            'chk_telegram_updates_raw_payload_type' =>
                "raw_payload IS NULL OR jsonb_typeof(raw_payload) = 'object'",
            'chk_telegram_updates_lease_state' =>
                "(status = 'PROCESSING' AND locked_by IS NOT NULL AND btrim(locked_by) <> '' "
                . "AND locked_until IS NOT NULL) OR (status <> 'PROCESSING' "
                . 'AND locked_by IS NULL AND locked_until IS NULL)',
            'chk_telegram_updates_retry_state' =>
                "(status = 'RETRY_SCHEDULED' AND next_attempt_at IS NOT NULL) "
                . "OR (status <> 'RETRY_SCHEDULED' AND next_attempt_at IS NULL)",
            'chk_telegram_updates_processed_state' =>
                "(status IN ('PROCESSED', 'FAILED', 'IGNORED') AND processed_at IS NOT NULL) "
                . "OR (status NOT IN ('PROCESSED', 'FAILED', 'IGNORED') AND processed_at IS NULL)",
            'chk_telegram_updates_payload_state' =>
                "status NOT IN ($knownStatuses) "
                . "OR (status IN ('RECEIVED', 'PROCESSING', 'RETRY_SCHEDULED') AND raw_payload IS NOT NULL) "
                . "OR (status IN ('PROCESSED', 'FAILED', 'IGNORED') AND raw_payload IS NULL)",
            'chk_telegram_updates_payload_retention' =>
                'raw_payload_expires_at >= received_at '
                . "AND raw_payload_expires_at <= received_at + INTERVAL '30 days'",
        ];
        foreach ($checks as $name => $expression) {
            $this->execute(
                'ALTER TABLE {{%telegram_updates}} ADD CONSTRAINT ' . $name . ' CHECK (' . $expression . ')',
            );
        }

        $this->createIndex(
            'idx_telegram_updates_status_next_attempt_received',
            '{{%telegram_updates}}',
            ['status', 'next_attempt_at', 'received_at'],
        );
        $this->createIndex(
            'idx_telegram_updates_status_locked_until',
            '{{%telegram_updates}}',
            ['status', 'locked_until'],
        );
        $this->execute(
            'CREATE INDEX idx_telegram_updates_raw_payload_expires_at '
            . 'ON {{%telegram_updates}} (raw_payload_expires_at) WHERE raw_payload IS NOT NULL',
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_telegram_updates_profile_id', '{{%telegram_updates}}');
        $this->dropTable('{{%telegram_updates}}');
    }
}
