<?php

declare(strict_types=1);

namespace core\application\query;

class GetUserByIdentityQuery
{
    public function __construct(
        public string $provider,
        public string $providerClientId,
    ) {
    }
}
