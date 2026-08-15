<?php

declare(strict_types=1);

namespace core\application\query;

class GetUserQuery
{
    /**
     * @param string[] $expand
     */
    public function __construct(
        public string $userId,
        public array $expand = [], // например, ['identities']
    ) {
    }
}
