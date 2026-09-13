<?php

declare(strict_types=1);

namespace modules\users\application\dto;

use modules\users\application\enum\UserAccountStatus;

final class ResolvedUserIdentityContext
{
    public function __construct(
        public readonly string $userId,
        public readonly string $userIdentityId,
        public readonly UserAccountStatus $userStatus,
        public readonly bool $created,
    ) {
    }
}
