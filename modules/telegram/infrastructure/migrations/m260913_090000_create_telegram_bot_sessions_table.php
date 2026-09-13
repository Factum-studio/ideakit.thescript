<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\migrations;

use yii\db\Migration;

final class m260913_090000_create_telegram_bot_sessions_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%telegram_bot_sessions}}', [
            'id' => 'uuid NOT NULL',
            'telegram_identity_profile_id' => 'uuid NOT NULL',
            'bot_key' => $this->string(64)->notNull(),
            'chat_id' => 'bigint NOT NULL',
            'flow_code' => $this->string(32)->notNull(),
            'content_mode' => $this->string(16)->notNull(),
            'content_subject_id' => 'uuid NULL',
            'agency_lead_id' => 'uuid NULL',
            'draft_comment' => $this->text()->null(),
            'expires_at' => 'timestamptz NULL',
            'interaction_revision' => 'bigint NOT NULL DEFAULT 0',
            'lock_version' => 'bigint NOT NULL DEFAULT 0',
            'created_at' => 'timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
        $this->addPrimaryKey('pk_telegram_bot_sessions', '{{%telegram_bot_sessions}}', 'id');
        $this->execute(
            'ALTER TABLE {{%telegram_bot_sessions}} '
            . 'ADD CONSTRAINT uq_telegram_bot_sessions_bot_key_chat_id UNIQUE (bot_key, chat_id)',
        );
        $this->addForeignKey(
            'fk_telegram_bot_sessions_profile_id',
            '{{%telegram_bot_sessions}}',
            'telegram_identity_profile_id',
            '{{%telegram_identity_profiles}}',
            'id',
            'RESTRICT',
            'RESTRICT',
        );
        $checks = [
            'chk_telegram_bot_sessions_flow_code' =>
                "flow_code IN ('BROWSING', 'AWAITING_LEAD_COMMENT', 'AWAITING_LEAD_CONFIRMATION', 'LEAD_REPLY')",
            'chk_telegram_bot_sessions_content_mode' => "content_mode IN ('DEMO', 'CATALOG')",
            'chk_telegram_bot_sessions_interaction_revision' => 'interaction_revision >= 0',
            'chk_telegram_bot_sessions_lock_version' => 'lock_version >= 0',
            'chk_telegram_bot_sessions_draft_state' =>
                "(flow_code = 'AWAITING_LEAD_CONFIRMATION' AND draft_comment IS NOT NULL AND expires_at IS NOT NULL) "
                . "OR (flow_code <> 'AWAITING_LEAD_CONFIRMATION' AND draft_comment IS NULL AND expires_at IS NULL)",
        ];
        foreach ($checks as $name => $expression) {
            $this->execute(
                'ALTER TABLE {{%telegram_bot_sessions}} ADD CONSTRAINT ' . $name . ' CHECK (' . $expression . ')',
            );
        }
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_telegram_bot_sessions_profile_id', '{{%telegram_bot_sessions}}');
        $this->dropTable('{{%telegram_bot_sessions}}');
    }
}
