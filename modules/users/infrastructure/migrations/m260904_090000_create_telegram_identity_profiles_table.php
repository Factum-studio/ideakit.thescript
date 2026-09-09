<?php

declare(strict_types=1);

namespace modules\users\infrastructure\migrations;

use yii\db\Migration;

final class m260904_090000_create_telegram_identity_profiles_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%telegram_identity_profiles}}', [
            'id' => 'uuid NOT NULL',
            'user_identity_id' => 'uuid NOT NULL',
            'username' => $this->string(64)->null(),
            'first_name' => $this->string(255)->null(),
            'last_name' => $this->string(255)->null(),
            'language_code' => $this->string(16)->null(),
            'bot_status' => $this->string(16)->notNull(),
            'catalog_exhausted_through_sequence_no' => 'bigint NULL',
            'catalog_exhausted_at' => 'timestamptz NULL',
            'catalog_notified_through_sequence_no' => 'bigint NULL',
            'first_seen_at' => 'timestamptz NOT NULL',
            'last_seen_at' => 'timestamptz NOT NULL',
            'blocked_at' => 'timestamptz NULL',
            'lock_version' => 'bigint NOT NULL DEFAULT 0',
            'created_at' => 'timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);

        $this->addPrimaryKey(
            'pk_telegram_identity_profiles',
            '{{%telegram_identity_profiles}}',
            'id',
        );
        $this->execute(
            'ALTER TABLE {{%telegram_identity_profiles}} '
            . 'ADD CONSTRAINT uq_telegram_identity_profiles_user_identity_id UNIQUE (user_identity_id)',
        );
        $this->addForeignKey(
            'fk_telegram_identity_profiles_user_identity_id',
            '{{%telegram_identity_profiles}}',
            'user_identity_id',
            '{{%user_identity}}',
            'id',
            'RESTRICT',
            'RESTRICT',
        );

        $this->execute(
            "ALTER TABLE {{%telegram_identity_profiles}} "
            . "ADD CONSTRAINT chk_telegram_identity_profiles_bot_status "
            . "CHECK (bot_status IN ('ACTIVE', 'BOT_BLOCKED', 'ANONYMIZED'))",
        );
        $this->execute(
            'ALTER TABLE {{%telegram_identity_profiles}} '
            . 'ADD CONSTRAINT chk_telegram_identity_profiles_seen_order '
            . 'CHECK (last_seen_at >= first_seen_at)',
        );
        $this->execute(
            'ALTER TABLE {{%telegram_identity_profiles}} '
            . 'ADD CONSTRAINT chk_telegram_identity_profiles_blocked_state '
            . "CHECK ((bot_status = 'BOT_BLOCKED' AND blocked_at IS NOT NULL AND blocked_at >= last_seen_at) "
            . "OR (bot_status <> 'BOT_BLOCKED' AND blocked_at IS NULL))",
        );
        $this->execute(
            'ALTER TABLE {{%telegram_identity_profiles}} '
            . 'ADD CONSTRAINT chk_telegram_identity_profiles_anonymized_snapshot '
            . "CHECK (bot_status <> 'ANONYMIZED' "
            . 'OR (username IS NULL AND first_name IS NULL AND last_name IS NULL AND language_code IS NULL))',
        );
        $this->execute(
            'ALTER TABLE {{%telegram_identity_profiles}} '
            . 'ADD CONSTRAINT chk_telegram_identity_profiles_catalog_exhausted_sequence '
            . 'CHECK (catalog_exhausted_through_sequence_no IS NULL '
            . 'OR catalog_exhausted_through_sequence_no >= 0)',
        );
        $this->execute(
            'ALTER TABLE {{%telegram_identity_profiles}} '
            . 'ADD CONSTRAINT chk_telegram_identity_profiles_catalog_notified_sequence '
            . 'CHECK (catalog_notified_through_sequence_no IS NULL '
            . 'OR catalog_notified_through_sequence_no >= 0)',
        );
        $this->execute(
            'ALTER TABLE {{%telegram_identity_profiles}} '
            . 'ADD CONSTRAINT chk_telegram_identity_profiles_lock_version '
            . 'CHECK (lock_version >= 0)',
        );

        $this->createIndex(
            'idx_telegram_identity_profiles_bot_status_last_seen_at',
            '{{%telegram_identity_profiles}}',
            ['bot_status', 'last_seen_at'],
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex(
            'idx_telegram_identity_profiles_bot_status_last_seen_at',
            '{{%telegram_identity_profiles}}',
        );
        $this->dropForeignKey(
            'fk_telegram_identity_profiles_user_identity_id',
            '{{%telegram_identity_profiles}}',
        );
        $this->dropTable('{{%telegram_identity_profiles}}');
    }
}
