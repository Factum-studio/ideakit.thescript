<?php

declare(strict_types=1);

namespace core\application\port;

use core\application\dto\UserFiltersDto;
use core\domain\entity\User;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;

interface IUserRepository
{
    public function save(User $user): void;
    public function findById(UserId $id): ?User;
    public function findByEmail(Email $email): ?User;
    public function findByPhone(Phone $phone): ?User;
    public function findByAuthKey(string $authKey): ?User;

    /**
     * Поиск с фильтрацией и пагинацией
     *
     * @param UserFiltersDto $filters
     * @return array{items: User[], total: int}
     */
    public function findWithFilters(UserFiltersDto $filters): array;

    public function delete(UserId $id): void;
}
