<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\application\port\IBackgroundCommandHandler;

final class BackgroundCommandRegistration
{
    /** @throws CriticalWorkerException */
    public function __construct(
        public readonly string $messageType,
        public readonly string $schemaVersion,
        public readonly IBackgroundCommandHandler $handler,
    ) {
        foreach ([[$messageType, 64], [$schemaVersion, 48]] as [$value, $limit]) {
            if (!mb_check_encoding($value, 'UTF-8') || trim($value) === ''
                || mb_strlen($value, 'UTF-8') > $limit || preg_match('/[\x00-\x1f\x7f]/', $value) !== 0
            ) {
                throw new CriticalWorkerException(CriticalWorkerError::CONFIGURATION_INVALID);
            }
        }
    }
}
