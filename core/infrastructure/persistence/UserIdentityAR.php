<?php

declare(strict_types=1);

namespace core\infrastructure\persistence;

use yii\db\ActiveRecord;

/**
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_client_id
 * @property string $created_at
 */
class UserIdentityAR extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%user_identity}}';
    }

    public function rules(): array
    {
        return [
            [['id', 'user_id', 'provider', 'provider_client_id'], 'required'],
            [['user_id', 'provider_client_id'], 'string', 'max' => 255],
            ['provider', 'string', 'max' => 50],
            [['provider', 'provider_client_id'], 'unique', 'targetAttribute' => ['provider', 'provider_client_id']],
            ['created_at', 'safe'],
        ];
    }
}
