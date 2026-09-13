<?php

declare(strict_types=1);

namespace modules\users\infrastructure\persistence;

use modules\users\domain\valueObject\TelegramBotStatus;
use yii\db\ActiveRecord;

/**
 * @property string $id
 * @property string $user_identity_id
 * @property string|null $username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $language_code
 * @property string $bot_status
 * @property int|null $catalog_exhausted_through_sequence_no
 * @property string|null $catalog_exhausted_at
 * @property int|null $catalog_notified_through_sequence_no
 * @property string $first_seen_at
 * @property string $last_seen_at
 * @property string|null $blocked_at
 * @property int $lock_version
 * @property string $created_at
 * @property string $updated_at
 */
final class TelegramIdentityProfileAR extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%telegram_identity_profiles}}';
    }

    public function optimisticLock(): string
    {
        return 'lock_version';
    }

    public function rules(): array
    {
        return [
            [['id', 'user_identity_id', 'bot_status', 'first_seen_at', 'last_seen_at'], 'required'],
            [['id', 'user_identity_id'], 'string', 'max' => 36],
            ['username', 'string', 'max' => 64],
            [['first_name', 'last_name'], 'string', 'max' => 255],
            ['language_code', 'string', 'max' => 16],
            ['bot_status', 'string', 'max' => 16],
            [
                'bot_status',
                'in',
                'range' => [
                    TelegramBotStatus::ACTIVE->value,
                    TelegramBotStatus::BOT_BLOCKED->value,
                    TelegramBotStatus::ANONYMIZED->value,
                ],
            ],
            [
                [
                    'catalog_exhausted_through_sequence_no',
                    'catalog_notified_through_sequence_no',
                    'lock_version',
                ],
                'integer',
                'min' => 0,
            ],
            [
                [
                    'catalog_exhausted_at',
                    'first_seen_at',
                    'last_seen_at',
                    'blocked_at',
                    'created_at',
                    'updated_at',
                ],
                'safe',
            ],
        ];
    }
}
