<?php

declare(strict_types=1);

namespace core\domain\exception;

use Throwable;

class IdentityAlreadyExistsException extends DomainException
{
    public function __construct(
        string     $message     = 'Identity already exists for this provider',
        int        $code        = 0,
        ?Throwable $previous    = null)
    {
        parent::__construct($message, 409, $code, $previous);
    }
}
