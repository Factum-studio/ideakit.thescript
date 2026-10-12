<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\http;

enum TelegramWebhookRejectionReason: string
{
    case INVALID_REQUEST = 'invalid_request';
    case FORBIDDEN = 'forbidden';
    case UNSUPPORTED_MEDIA_TYPE = 'unsupported_media_type';
    case PAYLOAD_TOO_LARGE = 'payload_too_large';
    case INVALID_UPDATE = 'invalid_update';
    case INTERNAL_FAILURE = 'internal_failure';
}
