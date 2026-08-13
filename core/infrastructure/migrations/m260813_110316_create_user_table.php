<?php

declare(strict_types=1);

namespace core\infrastructure\migrations;

use yii\db\Migration;

/**
 * Handles the creation of table `{{%user}}`.
 */
class m260813_110316_create_user_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%user}}', [
            'id'            => $this->primaryKey()->notNull(),
            'surname'       => $this->string(100)->notNull(),
            'name'          => $this->string(100)->notNull(),
            'patronymic'    => $this->string(100)->null(),
            'email'         => $this->string(255)->notNull()->unique(),
            'phone'         => $this->string(20)->null()->unique(),
            'role'          => $this->string(50)->notNull()->defaultValue('user'),
            'post'          => $this->string(100)->null(),
            'status'        => $this->smallInteger()->notNull()->defaultValue(1)->comment('1 – active, 0 – inactive'),
            'auth_key'      => $this->string(32)->notNull(),
            'created_at'    => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at'    => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'last_login_at' => $this->timestamp()->null(),
        ]);

        // Индексы
        $this->createIndex('idx-user-email', '{{%user}}', 'email');
        $this->createIndex('idx-user-phone', '{{%user}}', 'phone');
        $this->createIndex('idx-user-status', '{{%user}}', 'status');
        $this->createIndex('idx-user-auth_key', '{{%user}}', 'auth_key');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropIndex("idx-user-auth_key", '{{%user}}');
        $this->dropIndex("idx-user-status", '{{%user}}');
        $this->dropIndex("idx-user-phone", '{{%user}}');
        $this->dropIndex("idx-user-email", '{{%user}}');
        $this->dropTable('{{%user}}');
    }
}
