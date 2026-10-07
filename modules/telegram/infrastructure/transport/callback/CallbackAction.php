<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\callback;

enum CallbackAction: string
{
    case DETAILS = 'd';
    case ORDER = 'o';
    case PDF = 'p';
    case CONFIRM_ORDER = 'c';
    case CANCEL_ORDER = 'x';

    public function usesCardSubject(): bool
    {
        return match ($this) {
            self::DETAILS, self::ORDER, self::PDF => true,
            self::CONFIRM_ORDER, self::CANCEL_ORDER => false,
        };
    }
}
