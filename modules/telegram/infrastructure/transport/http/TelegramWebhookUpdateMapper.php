<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\http;

use LogicException;
use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\enum\IncomingTelegramUpdateType;
use modules\telegram\application\enum\TelegramUpdateIgnoreReason;
use modules\telegram\infrastructure\transport\update\CallbackQueryUpdate;
use modules\telegram\infrastructure\transport\update\IgnoredUpdate;
use modules\telegram\infrastructure\transport\update\IgnoredUpdateReason;
use modules\telegram\infrastructure\transport\update\InvalidTelegramUpdateException;
use modules\telegram\infrastructure\transport\update\MessageUpdate;
use modules\telegram\infrastructure\transport\update\MyChatMemberUpdate;
use modules\telegram\infrastructure\transport\update\TelegramUpdateParser;
use modules\telegram\infrastructure\transport\update\TelegramUpdateType;

final class TelegramWebhookUpdateMapper
{
    public function __construct(private readonly TelegramUpdateParser $parser)
    {
    }

    /** @throws InvalidTelegramUpdateException */
    public function fromRawPayload(string $botKey, string $rawPayload): AcceptTelegramUpdateCommand
    {
        $update = $this->parser->parse($rawPayload);
        $type = match ($update->type()) {
            TelegramUpdateType::MESSAGE => IncomingTelegramUpdateType::MESSAGE,
            TelegramUpdateType::CALLBACK_QUERY => IncomingTelegramUpdateType::CALLBACK_QUERY,
            TelegramUpdateType::MY_CHAT_MEMBER => IncomingTelegramUpdateType::MY_CHAT_MEMBER,
            TelegramUpdateType::UNSUPPORTED => IncomingTelegramUpdateType::UNSUPPORTED,
        };
        $chatId = match (true) {
            $update instanceof IgnoredUpdate => null,
            $update instanceof MessageUpdate,
            $update instanceof CallbackQueryUpdate,
            $update instanceof MyChatMemberUpdate => $update->chatId,
            default => throw new LogicException('unsupported_telegram_update_model'),
        };
        $reason = $update instanceof IgnoredUpdate ? match ($update->reason) {
            IgnoredUpdateReason::NON_PRIVATE_CHAT => TelegramUpdateIgnoreReason::NON_PRIVATE_CHAT,
            IgnoredUpdateReason::UNSUPPORTED_UPDATE_TYPE => TelegramUpdateIgnoreReason::UNSUPPORTED_UPDATE_TYPE,
            IgnoredUpdateReason::UNSUPPORTED_CONTENT => TelegramUpdateIgnoreReason::UNSUPPORTED_CONTENT,
            IgnoredUpdateReason::UNSUPPORTED_CALLBACK_CONTEXT => TelegramUpdateIgnoreReason::UNSUPPORTED_CALLBACK_CONTEXT,
            IgnoredUpdateReason::INVALID_STRUCTURE => TelegramUpdateIgnoreReason::INVALID_STRUCTURE,
            IgnoredUpdateReason::UNSUPPORTED_ACTOR => TelegramUpdateIgnoreReason::UNSUPPORTED_ACTOR,
        } : null;

        return new AcceptTelegramUpdateCommand(
            $botKey,
            $update->updateId(),
            $type,
            $chatId,
            $reason,
            $rawPayload,
            hash('sha256', $rawPayload),
        );
    }
}
