<?php

declare(strict_types=1);

namespace core\infrastructure\migrations;

use yii\db\Migration;

final class m260905_090000_allow_null_user_names extends Migration
{
    public function safeUp(): void
    {
        $this->execute(
            'ALTER TABLE {{%user}} ALTER COLUMN "surname" DROP NOT NULL',
        );
        $this->execute(
            'ALTER TABLE {{%user}} ALTER COLUMN "name" DROP NOT NULL',
        );
    }

    public function safeDown(): bool
    {
        $nullableUserCount = (int) $this->getDb()->createCommand(
            'SELECT COUNT(*) FROM {{%user}} WHERE "surname" IS NULL OR "name" IS NULL',
        )->queryScalar();

        if ($nullableUserCount > 0) {
            echo "Cannot restore required user names while nullable values exist.\n";

            return false;
        }

        $this->execute(
            'ALTER TABLE {{%user}} ALTER COLUMN "surname" SET NOT NULL',
        );
        $this->execute(
            'ALTER TABLE {{%user}} ALTER COLUMN "name" SET NOT NULL',
        );

        return true;
    }
}
