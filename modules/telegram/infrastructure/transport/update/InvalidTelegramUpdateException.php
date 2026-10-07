<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

use InvalidArgumentException;

final class InvalidTelegramUpdateException extends InvalidArgumentException
{
}
