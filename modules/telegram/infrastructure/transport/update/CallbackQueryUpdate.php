<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

use InvalidArgumentException;

final class CallbackQueryUpdate implements TelegramUpdate
{
    public function __construct(
        private readonly int $updateId,
        public readonly string $chatId,
        public readonly TelegramSenderSnapshot $sender,
        public readonly string $callbackQueryId,
        public readonly int $messageId,
        public readonly string $unverifiedData,
    ) {
        if ($updateId < 0) {
            throw new InvalidArgumentException('invalid_update_id');
        }

        if ($chatId === '') {
            throw new InvalidArgumentException('invalid_chat_id');
        }

        if ($callbackQueryId === '') {
            throw new InvalidArgumentException('invalid_callback_query_id');
        }

        if ($messageId <= 0) {
            throw new InvalidArgumentException('invalid_message_id');
        }
    }

    public function updateId(): int
    {
        return $this->updateId;
    }

    public function type(): TelegramUpdateType
    {
        return TelegramUpdateType::CALLBACK_QUERY;
    }
}
