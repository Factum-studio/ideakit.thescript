<?php

declare(strict_types=1);

namespace core\infrastructure\migrations;

use yii\db\Migration;

/**
 * Handles the creation of table `{{%user_identity}}`.
 */
class m260813_112057_create_user_identity_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%user_identity}}', [
            'id'                => $this->primaryKey()->notNull(),
            'user_id'           => $this->integer()->notNull(),
            'provider'          => $this->string(50)->notNull()->comment('telegram, internal, google, etc.'),
            'provider_client_id' => $this->string(255)->notNull(),
            'created_at'        => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        // Индексы
        $this->createIndex('idx-user_identity-user_id', '{{%user_identity}}', 'user_id');
        $this->createIndex(
            'idx-user_identity-provider-client',
            '{{%user_identity}}',
            ['provider', 'provider_client_id'],
            true,
        );

        // Внешний ключ к таблице user
        $this->addForeignKey(
            'fk-user_identity-user_id',
            '{{%user_identity}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE',
            'CASCADE',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropForeignKey('fk-user_identity-user_id', '{{%user_identity}}');
        $this->dropIndex('idx-user_identity-provider-client', '{{%user_identity}}');
        $this->dropIndex('idx-user_identity-user_id', '{{%user_identity}}');
        $this->dropTable('{{%user_identity}}');
    }
}
