<?php

declare(strict_types=1);

namespace core\domain\exception;

use Throwable;

class InvalidCredentialsException extends DomainException
{
    public function __construct(
        string     $message     = 'Invalid credentials',
        int        $code        = 0,
        ?Throwable $previous    = null)
    {
        parent::__construct($message, 401, $code, $previous);
    }
}
