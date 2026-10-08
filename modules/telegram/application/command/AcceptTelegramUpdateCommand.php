<?php

declare(strict_types=1);

namespace modules\telegram\application\command;

use InvalidArgumentException;
use modules\telegram\application\enum\IncomingTelegramUpdateType;
use modules\telegram\application\enum\TelegramUpdateIgnoreReason;

final class AcceptTelegramUpdateCommand
{
    public const MAX_PAYLOAD_BYTES = 65_536;
    public const PAYLOAD_SCHEMA_VERSION = 'telegram.update/1.0';

    public readonly string $payloadSchemaVersion;

    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $botKey,
        public readonly int $telegramUpdateId,
        public readonly IncomingTelegramUpdateType $updateType,
        public readonly ?string $chatId,
        public readonly ?TelegramUpdateIgnoreReason $ignoredReason,
        public readonly string $rawPayload,
        public readonly string $payloadHash,
    ) {
        if (trim($botKey) !== $botKey || preg_match('/\A\S(?:.{0,62}\S)?\z/us', $botKey) !== 1
            || $telegramUpdateId < 0 || $rawPayload === '' || strlen($rawPayload) > self::MAX_PAYLOAD_BYTES
            || preg_match('/\A[a-f0-9]{64}\z/', $payloadHash) !== 1
            || ($ignoredReason === null && ($chatId === null || $updateType === IncomingTelegramUpdateType::UNSUPPORTED))
            || ($ignoredReason !== null && $chatId !== null)
            || ($chatId !== null && !self::isChatId($chatId))) {
            throw new InvalidArgumentException('invalid_telegram_update_acceptance_command');
        }

        $this->payloadSchemaVersion = self::PAYLOAD_SCHEMA_VERSION;
    }

    private static function isChatId(string $value): bool
    {
        if (preg_match('/\A-?[1-9][0-9]{0,18}\z/', $value) !== 1) {
            return false;
        }

        $negative = $value[0] === '-';
        $digits = $negative ? substr($value, 1) : $value;
        $maximum = $negative ? '9223372036854775808' : '9223372036854775807';

        return strlen($digits) < 19 || strcmp($digits, $maximum) <= 0;
    }
}
