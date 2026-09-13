<?php

declare(strict_types=1);

namespace modules\users\application\exception;

use RuntimeException;

final class TelegramIdentityProfileConcurrencyException extends RuntimeException
{
}
