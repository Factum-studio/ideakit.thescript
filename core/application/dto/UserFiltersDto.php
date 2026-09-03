<?php

declare(strict_types=1);

namespace core\application\dto;

class UserFiltersDto
{
    /**
     * @param string[]|null $ids
     * @param string[]|null $orderBy
     */
    public function __construct(
        public ?array $ids          = null,
        public ?string $email       = null,
        public ?string $phone       = null,
        public ?string $role        = null,
        public ?string $post        = null,
        public ?int $status         = null,
        public ?string $createdFrom = null,
        public ?string $createdTo   = null,
        public ?string $updatedFrom = null,
        public ?string $updatedTo   = null,
        public ?string $lastLoginFrom = null,
        public ?string $lastLoginTo = null,
        public ?int $limit          = null,
        public ?int $offset         = null,
        public ?array $orderBy      = null,
    ) {
    }
}
