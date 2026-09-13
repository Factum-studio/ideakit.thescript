<?php

declare(strict_types=1);

namespace modules\users\application\exception;

use RuntimeException;
use Throwable;

final class TelegramIdentityResolutionConcurrencyException extends RuntimeException
{
    private function __construct(
        public readonly bool $retryable,
        Throwable $previous,
    ) {
        parent::__construct(
            'telegram_identity_resolution_concurrency_conflict',
            0,
            $previous,
        );
    }

    public static function from(Throwable $previous): self
    {
        return new self(true, $previous);
    }
}
