<?php

declare(strict_types=1);

namespace core\application\query;

class FindUserQuery
{
    // @phpstan-ignore-next-line
    public function __construct(
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
        public int $limit           = 20,
        public int $page            = 1,
        public ?array $orderBy      = null,
    ) {
    }
}
