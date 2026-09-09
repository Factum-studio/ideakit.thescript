<?php

declare(strict_types=1);

namespace core\application\dto;

final class ResolvedUserIdentity
{
    public function __construct(
        public readonly string $userId,
        public readonly string $userIdentityId,
        public readonly int $userStatus,
        public readonly bool $created,
    ) {
    }
}
