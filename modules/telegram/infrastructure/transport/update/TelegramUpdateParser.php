<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\update;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use stdClass;

final class TelegramUpdateParser
{
    public const MAX_PAYLOAD_BYTES = 65_536;
    public const MAX_JSON_DEPTH = 32;

    public function parse(string $json): TelegramUpdate
    {
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidTelegramUpdateException('payload_too_large');
        }

        try {
            $root = json_decode($json, false, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new InvalidTelegramUpdateException('invalid_json', 0, $exception);
        }

        if (!$root instanceof stdClass) {
            throw new InvalidTelegramUpdateException('invalid_root');
        }

        if (!isset($root->update_id) || !is_int($root->update_id) || $root->update_id < 0) {
            throw new InvalidTelegramUpdateException('invalid_update_id');
        }

        $known = [];
        foreach (['message', 'callback_query', 'my_chat_member'] as $field) {
            if (property_exists($root, $field)) {
                $known[] = $field;
            }
        }

        if ($known === []) {
            return new IgnoredUpdate(
                $root->update_id,
                TelegramUpdateType::UNSUPPORTED,
                IgnoredUpdateReason::UNSUPPORTED_UPDATE_TYPE,
            );
        }

        if (count($known) !== 1) {
            return new IgnoredUpdate(
                $root->update_id,
                TelegramUpdateType::UNSUPPORTED,
                IgnoredUpdateReason::INVALID_STRUCTURE,
            );
        }

        $field = $known[0];
        $type = match ($field) {
            'message' => TelegramUpdateType::MESSAGE,
            'callback_query' => TelegramUpdateType::CALLBACK_QUERY,
            'my_chat_member' => TelegramUpdateType::MY_CHAT_MEMBER,
        };
        $event = $root->{$field};

        if (!$event instanceof stdClass) {
            return new IgnoredUpdate($root->update_id, $type, IgnoredUpdateReason::INVALID_STRUCTURE);
        }

        $callbackQueryId = null;
        if ($type === TelegramUpdateType::CALLBACK_QUERY) {
            if (!isset($event->id) || !is_string($event->id) || $event->id === '' || strlen($event->id) > 256) {
                return new IgnoredUpdate($root->update_id, $type, IgnoredUpdateReason::INVALID_STRUCTURE);
            }

            $callbackQueryId = $event->id;
            if (!isset($event->message) || !$event->message instanceof stdClass) {
                return new IgnoredUpdate(
                    $root->update_id,
                    $type,
                    IgnoredUpdateReason::UNSUPPORTED_CALLBACK_CONTEXT,
                    $callbackQueryId,
                );
            }

            $message = $event->message;
            if (!isset($message->chat) || !$message->chat instanceof stdClass) {
                return new IgnoredUpdate(
                    $root->update_id,
                    $type,
                    IgnoredUpdateReason::UNSUPPORTED_CALLBACK_CONTEXT,
                    $callbackQueryId,
                );
            }
        } else {
            $message = $event;
        }

        if (!isset($message->chat) || !$message->chat instanceof stdClass || !isset($message->chat->type)
            || !is_string($message->chat->type)) {
            return new IgnoredUpdate($root->update_id, $type, IgnoredUpdateReason::INVALID_STRUCTURE, $callbackQueryId);
        }

        if (in_array($message->chat->type, ['group', 'supergroup', 'channel'], true)) {
            return new IgnoredUpdate($root->update_id, $type, IgnoredUpdateReason::NON_PRIVATE_CHAT, $callbackQueryId);
        }

        if ($message->chat->type !== 'private') {
            return new IgnoredUpdate($root->update_id, $type, IgnoredUpdateReason::INVALID_STRUCTURE, $callbackQueryId);
        }

        if ($type === TelegramUpdateType::MESSAGE) {
            return $this->parseMessage($root->update_id, $event);
        }

        if ($type === TelegramUpdateType::CALLBACK_QUERY) {
            return $this->parseCallback($root->update_id, $event, $message, $callbackQueryId);
        }

        return $this->parseChatMember($root->update_id, $event);
    }

    private function parseChatMember(int $updateId, stdClass $event): TelegramUpdate
    {
        $chatId = $this->chatId($event->chat->id ?? null);
        if ($chatId === null || !isset($event->from) || !$event->from instanceof stdClass
            || !isset($event->from->is_bot) || !is_bool($event->from->is_bot)) {
            return new IgnoredUpdate($updateId, TelegramUpdateType::MY_CHAT_MEMBER, IgnoredUpdateReason::INVALID_STRUCTURE);
        }

        $actor = $this->sender($event->from);
        $old = $event->old_chat_member ?? null;
        $new = $event->new_chat_member ?? null;
        if ($actor === null || !isset($event->date) || !is_int($event->date) || $event->date < 0
            || !$old instanceof stdClass || !$new instanceof stdClass) {
            return new IgnoredUpdate($updateId, TelegramUpdateType::MY_CHAT_MEMBER, IgnoredUpdateReason::INVALID_STRUCTURE);
        }

        $oldMemberId = $this->memberId($old);
        $newMemberId = $this->memberId($new);
        $oldStatus = isset($old->status) && is_string($old->status)
            ? TelegramChatMemberStatus::tryFrom($old->status) : null;
        $newStatus = isset($new->status) && is_string($new->status)
            ? TelegramChatMemberStatus::tryFrom($new->status) : null;
        $utc = new DateTimeZone('UTC');
        $occurredAt = DateTimeImmutable::createFromFormat('!U', (string) $event->date, $utc);

        if ($oldMemberId === null || $newMemberId !== $oldMemberId
            || $oldStatus === null || $newStatus === null || $occurredAt === false) {
            return new IgnoredUpdate($updateId, TelegramUpdateType::MY_CHAT_MEMBER, IgnoredUpdateReason::INVALID_STRUCTURE);
        }

        $occurredAt = $occurredAt->setTimezone($utc);

        return new MyChatMemberUpdate(
            $updateId,
            $chatId,
            $actor->userId,
            $oldMemberId,
            $oldStatus,
            $newStatus,
            $occurredAt,
        );
    }

    private function memberId(stdClass $membership): ?string
    {
        if (!isset($membership->user) || !$membership->user instanceof stdClass) {
            return null;
        }

        return $this->userId($membership->user->id ?? null);
    }

    private function parseCallback(
        int $updateId,
        stdClass $callback,
        stdClass $message,
        string $callbackQueryId,
    ): TelegramUpdate {
        if (!isset($callback->from) || !$callback->from instanceof stdClass
            || !isset($callback->from->is_bot) || !is_bool($callback->from->is_bot)) {
            return new IgnoredUpdate(
                $updateId,
                TelegramUpdateType::CALLBACK_QUERY,
                IgnoredUpdateReason::INVALID_STRUCTURE,
                $callbackQueryId,
            );
        }

        if ($callback->from->is_bot) {
            return new IgnoredUpdate(
                $updateId,
                TelegramUpdateType::CALLBACK_QUERY,
                IgnoredUpdateReason::UNSUPPORTED_ACTOR,
                $callbackQueryId,
            );
        }

        $chatId = $this->chatId($message->chat->id ?? null);
        $sender = $this->sender($callback->from);
        if ($chatId === null || $sender === null || !isset($message->message_id)
            || !is_int($message->message_id) || $message->message_id <= 0) {
            return new IgnoredUpdate(
                $updateId,
                TelegramUpdateType::CALLBACK_QUERY,
                IgnoredUpdateReason::INVALID_STRUCTURE,
                $callbackQueryId,
            );
        }

        if (!property_exists($callback, 'data')) {
            return new IgnoredUpdate(
                $updateId,
                TelegramUpdateType::CALLBACK_QUERY,
                IgnoredUpdateReason::UNSUPPORTED_CALLBACK_CONTEXT,
                $callbackQueryId,
            );
        }

        if (!is_string($callback->data) || $callback->data === '' || strlen($callback->data) > 64) {
            return new IgnoredUpdate(
                $updateId,
                TelegramUpdateType::CALLBACK_QUERY,
                IgnoredUpdateReason::INVALID_STRUCTURE,
                $callbackQueryId,
            );
        }

        return new CallbackQueryUpdate(
            $updateId,
            $chatId,
            $sender,
            $callbackQueryId,
            $message->message_id,
            $callback->data,
        );
    }

    private function parseMessage(int $updateId, stdClass $message): TelegramUpdate
    {
        if (!isset($message->from) || !$message->from instanceof stdClass
            || !isset($message->from->is_bot) || !is_bool($message->from->is_bot)) {
            return new IgnoredUpdate($updateId, TelegramUpdateType::MESSAGE, IgnoredUpdateReason::INVALID_STRUCTURE);
        }

        if ($message->from->is_bot) {
            return new IgnoredUpdate($updateId, TelegramUpdateType::MESSAGE, IgnoredUpdateReason::UNSUPPORTED_ACTOR);
        }

        $chatId = $this->chatId($message->chat->id ?? null);
        $sender = $this->sender($message->from);
        if ($chatId === null || $sender === null || !isset($message->message_id)
            || !is_int($message->message_id) || $message->message_id <= 0) {
            return new IgnoredUpdate($updateId, TelegramUpdateType::MESSAGE, IgnoredUpdateReason::INVALID_STRUCTURE);
        }

        if (!property_exists($message, 'text')) {
            return new IgnoredUpdate($updateId, TelegramUpdateType::MESSAGE, IgnoredUpdateReason::UNSUPPORTED_CONTENT);
        }

        if (!is_string($message->text) || $message->text === '' || mb_strlen($message->text, 'UTF-8') > 4096) {
            return new IgnoredUpdate($updateId, TelegramUpdateType::MESSAGE, IgnoredUpdateReason::INVALID_STRUCTURE);
        }

        return new MessageUpdate($updateId, $chatId, $sender, $message->message_id, $message->text);
    }

    private function sender(stdClass $user): ?TelegramSenderSnapshot
    {
        $id = $this->userId($user->id ?? null);
        if ($id === null || !isset($user->first_name) || !is_string($user->first_name)
            || $user->first_name === '' || mb_strlen($user->first_name, 'UTF-8') > 255) {
            return null;
        }

        $username = $this->optionalString($user, 'username', 64);
        $lastName = $this->optionalString($user, 'last_name', 255);
        $languageCode = $this->optionalString($user, 'language_code', 16);
        if ($username === false || $lastName === false || $languageCode === false) {
            return null;
        }

        return new TelegramSenderSnapshot($id, $user->first_name, $username, $lastName, $languageCode);
    }

    private function optionalString(stdClass $object, string $field, int $maxCharacters): string|false|null
    {
        if (!property_exists($object, $field)) {
            return null;
        }

        $value = $object->{$field};
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maxCharacters) {
            return false;
        }

        return $value;
    }

    private function userId(mixed $value): ?string
    {
        return is_int($value) && $value > 0 ? (string) $value : null;
    }

    private function chatId(mixed $value): ?string
    {
        return is_int($value) && $value !== 0 ? (string) $value : null;
    }
}
