<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\callback;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class VerifiedCallback
{
    private function __construct(
        public readonly CallbackAction $action,
        public readonly string|int $subject,
    ) {
    }

    public static function card(CallbackAction $action, string $uuid): self
    {
        if (!$action->usesCardSubject() || !Uuid::isValid($uuid)) {
            throw new InvalidArgumentException('invalid_callback_subject');
        }

        return new self($action, Uuid::fromString($uuid)->toString());
    }

    public static function revision(CallbackAction $action, int $revision): self
    {
        if ($action->usesCardSubject() || $revision < 0) {
            throw new InvalidArgumentException('invalid_callback_subject');
        }

        return new self($action, $revision);
    }
}
