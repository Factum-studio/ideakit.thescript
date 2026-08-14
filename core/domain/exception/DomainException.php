<?php

declare(strict_types=1);

namespace core\domain\exception;

use Throwable;
use yii\base\Exception;

abstract class DomainException extends Exception implements IHttpException
{
    private int $statusCode;

    public function __construct(
        string     $message     = '',
        int        $statusCode  = 500,
        int        $code        = 0,
        ?Throwable $previous    = null,
    ) {
        $this->statusCode = $statusCode;
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
