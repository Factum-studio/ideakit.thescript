<?php

declare(strict_types=1);

namespace modules\platform\application\exception;

use RuntimeException;

final class BackgroundCommandRejectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('handler_rejected');
    }
}
