<?php

declare(strict_types=1);

namespace modules\platform\application\exception;

use modules\platform\application\enum\BrokerTransportErrorCode;
use RuntimeException;

final class BrokerTransportException extends RuntimeException
{
    public function __construct(public readonly BrokerTransportErrorCode $errorCode)
    {
        parent::__construct($errorCode->value);
    }
}
