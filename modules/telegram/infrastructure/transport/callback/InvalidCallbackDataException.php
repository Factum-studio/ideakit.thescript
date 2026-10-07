<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\callback;

use InvalidArgumentException;

final class InvalidCallbackDataException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('invalid_callback_data');
    }
}
