<?php

declare(strict_types=1);

namespace modules\telegram\application\enum;

enum TelegramInboxReservationOutcome: string
{
    case CREATED = 'CREATED';
    case EXISTING = 'EXISTING';
}
