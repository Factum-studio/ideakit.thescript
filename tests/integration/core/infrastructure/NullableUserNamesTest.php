<?php

declare(strict_types=1);

namespace tests\integration\core\infrastructure;

use Codeception\Test\Unit;
use core\domain\entity\User;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserStatus;
use core\infrastructure\repository\DbUserRepository;
use DateTimeImmutable;
use Yii;

final class NullableUserNamesTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ad0';

    protected function _before(): void
    {
        $this->deleteTestUser();
    }

    protected function _after(): void
    {
        $this->deleteTestUser();
    }

    public function testPersistsAndRestoresUserWithoutProfileNames(): void
    {
        $now = new DateTimeImmutable('2026-09-05 10:00:00+00:00');
        $user = new User(
            new UserId(self::USER_ID),
            null,
            null,
            null,
            null,
            null,
            new Role(Role::ROLE_USER),
            null,
            new UserStatus(UserStatus::STATUS_ACTIVE),
            Yii::$app->getSecurity()->generateRandomString(),
            $now,
            $now,
        );
        $repository = new DbUserRepository();

        $repository->save($user);
        $restored = $repository->findById($user->getId());

        self::assertNotNull($restored);
        self::assertNull($restored->getSurname());
        self::assertNull($restored->getName());
    }

    private function deleteTestUser(): void
    {
        Yii::$app->db
            ->createCommand()
            ->delete('{{%user}}', ['id' => self::USER_ID])
            ->execute();
    }
}
