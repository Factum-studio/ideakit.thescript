<?php

declare(strict_types=1);

namespace core\application\port;

use core\application\dto\JwtPayloadDto;
use core\domain\entity\User;

interface IJwtManager
{
    public function generate(User $user): string;
    public function validate(string $token): ?JwtPayloadDto;
}
