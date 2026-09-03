<?php

declare(strict_types=1);

namespace core\domain\exception;

use Throwable;

class UserAlreadyExistsException extends DomainException
{
    public function __construct(
        string     $message     = 'User already exists',
        int        $code        = 0,
        ?Throwable $previous    = null,
    ) {
        parent::__construct($message, 409, $code, $previous);
    }
}
