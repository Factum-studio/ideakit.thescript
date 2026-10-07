<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

use DateTimeImmutable;
use InvalidArgumentException;

final class MyChatMemberUpdate implements TelegramUpdate
{
    public function __construct(
        private readonly int $updateId,
        public readonly string $chatId,
        public readonly string $actorUserId,
        public readonly string $changedMemberUserId,
        public readonly TelegramChatMemberStatus $oldStatus,
        public readonly TelegramChatMemberStatus $newStatus,
        public readonly DateTimeImmutable $occurredAt,
    ) {
        if ($updateId < 0) {
            throw new InvalidArgumentException('invalid_update_id');
        }

        if ($chatId === '') {
            throw new InvalidArgumentException('invalid_chat_id');
        }

        if ($actorUserId === '') {
            throw new InvalidArgumentException('invalid_actor_user_id');
        }

        if ($changedMemberUserId === '') {
            throw new InvalidArgumentException('invalid_changed_member_user_id');
        }

        if ($occurredAt->getTimezone()->getName() !== 'UTC') {
            throw new InvalidArgumentException('occurred_at_must_be_utc');
        }
    }

    public function updateId(): int
    {
        return $this->updateId;
    }

    public function type(): TelegramUpdateType
    {
        return TelegramUpdateType::MY_CHAT_MEMBER;
    }
}
