<?php

declare(strict_types=1);

namespace core\domain\exception;

use Throwable;

class PermissionDeniedException extends DomainException
{
    public function __construct(
        string $message = 'Permission denied',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 403, $code, $previous);
    }
}
