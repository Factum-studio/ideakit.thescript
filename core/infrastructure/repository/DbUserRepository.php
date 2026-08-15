<?php

declare(strict_types=1);

namespace core\infrastructure\repository;

use core\application\dto\UserFiltersDto;
use core\application\port\IUserRepository;
use core\domain\entity\User;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use core\infrastructure\persistence\UserAR;
use DateTimeImmutable;
use Exception;
use RuntimeException;
use Yii;
use yii\db\Exception as DbException;
use yii\db\Query;

class DbUserRepository implements IUserRepository
{
    /**
     * @throws DbException
     */
    public function save(User $user): void
    {
        $ar             = $this->findOrCreateAR($user->getId());
        $isNew          = $ar->isNewRecord;

        $ar->surname    = $user->getSurname();
        $ar->name       = $user->getName();
        $ar->patronymic = $user->getPatronymic();
        $ar->email      = $user->getEmail()?->value();
        $ar->phone      = $user->getPhone()?->value();
        $ar->role       = $user->getRole()->value();
        $ar->post       = $user->getPost();
        $ar->status     = $user->getStatus()->value();
        $ar->auth_key   = $user->getAuthKey();

        if ($isNew) {
            $ar->created_at = $user->getCreatedAt()->format('Y-m-d H:i:s');
        }

        $ar->updated_at     = $user->getUpdatedAt()->format('Y-m-d H:i:s');
        $ar->last_login_at  = $user->getLastLoginAt()?->format('Y-m-d H:i:s');

        if (!$ar->save()) {
            $errorMessages = [];
            foreach ($ar->getErrors() as $field => $messages) {
                $errorMessages[] = "$field: " . implode(', ', $messages);
            }
            throw new RuntimeException('Failed to save user: ' . implode('; ', $errorMessages));
        }
    }

    /**
     * @throws Exception
     */
    public function findById(UserId $id): ?User
    {
        $ar = UserAR::findOne($id->value());
        return $ar ? $this->hydrate($ar) : null;
    }

    /**
     * @throws Exception
     */
    public function findByEmail(Email $email): ?User
    {
        $ar = UserAR::findOne(['email' => $email->value()]);
        return $ar ? $this->hydrate($ar) : null;
    }

    /**
     * @throws Exception
     */
    public function findByPhone(Phone $phone): ?User
    {
        $ar = UserAR::findOne(['phone' => $phone->value()]);
        return $ar ? $this->hydrate($ar) : null;
    }

    /**
     * @throws Exception
     */
    public function findByAuthKey(string $authKey): ?User
    {
        $ar = UserAR::findOne(['auth_key' => $authKey]);
        return $ar ? $this->hydrate($ar) : null;
    }

    public function findWithFilters(UserFiltersDto $filters): array
    {
        $query = (new Query())
            ->from(UserAR::tableName());

        if (!empty($filters->ids)) {
            $query->andWhere(['id' => $filters->ids]);
        }
        if (!empty($filters->email)) {
            $query->andWhere(['email' => $filters->email]);
        }
        if (!empty($filters->phone)) {
            $query->andWhere(['phone' => $filters->phone]);
        }
        if (!empty($filters->role)) {
            $query->andWhere(['role' => $filters->role]);
        }
        if (!empty($filters->post)) {
            $query->andWhere(['post' => $filters->post]);
        }
        if (isset($filters->status)) {
            $query->andWhere(['status' => $filters->status]);
        }
        if (!empty($filters->createdFrom)) {
            $query->andWhere(['>=', 'created_at', $filters->createdFrom]);
        }
        if (!empty($filters->createdTo)) {
            $query->andWhere(['<=', 'created_at', $filters->createdTo]);
        }
        if (!empty($filters->updatedFrom)) {
            $query->andWhere(['>=', 'updated_at', $filters->updatedFrom]);
        }
        if (!empty($filters->updatedTo)) {
            $query->andWhere(['<=', 'updated_at', $filters->updatedTo]);
        }
        if (!empty($filters->lastLoginFrom)) {
            $query->andWhere(['>=', 'last_login_at', $filters->lastLoginFrom]);
        }
        if (!empty($filters->lastLoginTo)) {
            $query->andWhere(['<=', 'last_login_at', $filters->lastLoginTo]);
        }

        $total = $query->count();

        if (!empty($filters->orderBy)) {
            $query->orderBy($filters->orderBy);
        } else {
            $query->orderBy(['created_at' => SORT_DESC]);
        }

        if ($filters->limit !== null) {
            $query->limit($filters->limit);
        }
        if ($filters->offset !== null) {
            $query->offset($filters->offset);
        }

        $rows = $query->all();
        $items = array_map(/**
         * @throws Exception
         */ function ($row) {
            return $this->hydrateFromArray($row);
        }, $rows);

        return ['items' => $items, 'total' => (int)$total];
    }

    public function delete(UserId $id): void
    {
        UserAR::deleteAll(['id' => $id->value()]);
    }

    private function findOrCreateAR(UserId $id): UserAR
    {
        $ar = UserAR::findOne($id->value());
        if (!$ar) {
            $ar     = new UserAR();
            $ar->id = $id->value();
        }
        return $ar;
    }

    /**
     * @throws Exception
     */
    private function hydrate(UserAR $ar): User
    {
        return new User(
            new UserId($ar->id),
            $ar->surname,
            $ar->name,
            $ar->patronymic,
            $ar->email ? new Email($ar->email) : null,
            $ar->phone ? new Phone($ar->phone) : null,
            new Role($ar->role),
            $ar->post,
            new UserStatus((int)$ar->status),
            $ar->auth_key,
            new DateTimeImmutable($ar->created_at),
            new DateTimeImmutable($ar->updated_at),
            $ar->last_login_at ? new DateTimeImmutable($ar->last_login_at) : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @throws Exception
     */
    private function hydrateFromArray(array $row): User
    {
        return new User(
            new UserId($row['id']),
            $row['surname'],
            $row['name'],
            $row['patronymic'] ?? null,
            $row['email'] ? new Email($row['email']) : null,
            $row['phone'] ? new Phone($row['phone']) : null,
            new Role($row['role']),
            $row['post'] ?? null,
            new UserStatus((int)$row['status']),
            $row['auth_key'],
            new DateTimeImmutable($row['created_at']),
            new DateTimeImmutable($row['updated_at']),
            $row['last_login_at'] ? new DateTimeImmutable($row['last_login_at']) : null,
        );
    }
}
