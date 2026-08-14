<?php

declare(strict_types=1);

namespace core\infrastructure\persistence;

use yii\db\ActiveRecord;

/**
 * @property string $id
 * @property string $surname
 * @property string $name
 * @property string|null $patronymic
 * @property string|null $email
 * @property string|null $phone
 * @property string $role
 * @property string|null $post
 * @property int $status
 * @property string $auth_key
 * @property string $created_at
 * @property string $updated_at
 * @property string|null $last_login_at
 */
class UserAR extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%user}}';
    }

    public function rules(): array
    {
        return [
            [['id', 'surname', 'name', 'auth_key'], 'required'],
            [['surname', 'name', 'patronymic'], 'string', 'max' => 100],
            ['email', 'email'],
            ['email', 'unique'],
            ['phone', 'string', 'max' => 20],
            ['phone', 'unique'],
            ['role', 'string', 'max' => 50],
            ['role', 'default', 'value' => 'user'],
            ['post', 'string', 'max' => 100],
            ['status', 'integer'],
            ['status', 'default', 'value' => 1],
            ['auth_key', 'string', 'max' => 32],
            [['created_at', 'updated_at', 'last_login_at'], 'safe'],
        ];
    }
}
