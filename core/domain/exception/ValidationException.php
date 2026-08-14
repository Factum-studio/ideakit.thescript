<?php

declare(strict_types=1);

namespace core\domain\exception;

use Throwable;

class ValidationException extends DomainException
{
    private array $errors;

    public function __construct(
        string     $message     = 'Validation failed',
        array      $errors      = [],
        int        $code        = 0,
        ?Throwable $previous    = null,
    ) {
        $this->errors = $errors;
        parent::__construct($message, 400, $code, $previous);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
