<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\callback;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class TelegramCallbackCodec
{
    private const VERSION = '1';
    private const HMAC_CONTEXT = 'ideakit:telegram-callback:v1';
    private const BASE36_DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

    public function __construct(private readonly CallbackSigningKeys $keys)
    {
    }

    public function encodeCard(CallbackAction $action, string $cardUuid, string $botKey, string $profileId): string
    {
        VerifiedCallback::card($action, $cardUuid);
        $subject = self::base64UrlEncode(Uuid::fromString($cardUuid)->getBytes());

        return $this->encode($action, $subject, $botKey, $profileId);
    }

    public function encodeRevision(CallbackAction $action, int $revision, string $botKey, string $profileId): string
    {
        VerifiedCallback::revision($action, $revision);
        $subject = self::base36Encode($revision);

        return $this->encode($action, $subject, $botKey, $profileId);
    }

    public function decode(string $data, string $botKey, string $profileId): VerifiedCallback
    {
        if ($data === '' || strlen($data) > 64 || substr_count($data, '.') !== 3 || !preg_match('/\A[ -~]+\z/D', $data)) {
            throw new InvalidCallbackDataException();
        }

        [$version, $actionCode, $subject, $signature] = explode('.', $data);
        $action = CallbackAction::tryFrom($actionCode);
        if ($version !== self::VERSION || $action === null) {
            throw new InvalidCallbackDataException();
        }

        $decodedSubject = $action->usesCardSubject()
            ? self::decodeCardSubject($subject)
            : self::decodeRevisionSubject($subject);
        $mac = self::base64UrlDecode16($signature);

        try {
            $profile = self::normalizeContext($botKey, $profileId);
        } catch (InvalidArgumentException) {
            throw new InvalidCallbackDataException();
        }

        $message = self::signedMessage($botKey, $version, $actionCode, $subject, $profile);
        if (!$this->keys->verify($message, $mac)) {
            throw new InvalidCallbackDataException();
        }

        return $action->usesCardSubject()
            ? VerifiedCallback::card($action, $decodedSubject)
            : VerifiedCallback::revision($action, $decodedSubject);
    }

    private function encode(CallbackAction $action, string $subject, string $botKey, string $profileId): string
    {
        $profile = self::normalizeContext($botKey, $profileId);
        $message = self::signedMessage($botKey, self::VERSION, $action->value, $subject, $profile);
        $signature = self::base64UrlEncode($this->keys->sign($message));
        $wire = self::VERSION . '.' . $action->value . '.' . $subject . '.' . $signature;

        if (strlen($wire) > 64) {
            throw new InvalidArgumentException('invalid_callback_length');
        }

        return $wire;
    }

    private static function normalizeContext(string $botKey, string $profileId): string
    {
        if ($botKey === '' || strlen($botKey) > 64 || !Uuid::isValid($profileId)) {
            throw new InvalidArgumentException('invalid_callback_context');
        }

        return Uuid::fromString($profileId)->toString();
    }

    private static function signedMessage(
        string $botKey,
        string $version,
        string $action,
        string $subject,
        string $profileId,
    ): string {
        $message = self::HMAC_CONTEXT;
        foreach ([$botKey, $version, $action, $subject, $profileId] as $field) {
            $message .= pack('N', strlen($field)) . $field;
        }

        return $message;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode16(string $value): string
    {
        if (strlen($value) !== 22 || !preg_match('/\A[A-Za-z0-9_-]{22}\z/D', $value)) {
            throw new InvalidCallbackDataException();
        }

        $decoded = base64_decode(strtr($value, '-_', '+/') . '==', true);
        if ($decoded === false || strlen($decoded) !== 16 || self::base64UrlEncode($decoded) !== $value) {
            throw new InvalidCallbackDataException();
        }

        return $decoded;
    }

    private static function decodeCardSubject(string $subject): string
    {
        return Uuid::fromBytes(self::base64UrlDecode16($subject))->toString();
    }

    private static function decodeRevisionSubject(string $subject): int
    {
        if (!preg_match('/\A(?:0|[1-9a-z][0-9a-z]*)\z/D', $subject)) {
            throw new InvalidCallbackDataException();
        }

        $value = 0;
        foreach (str_split($subject) as $character) {
            $digit = strpos(self::BASE36_DIGITS, $character);
            if ($digit === false || $value > intdiv(PHP_INT_MAX - $digit, 36)) {
                throw new InvalidCallbackDataException();
            }

            $value = $value * 36 + $digit;
        }

        return $value;
    }

    private static function base36Encode(int $value): string
    {
        if ($value === 0) {
            return '0';
        }

        $encoded = '';
        while ($value > 0) {
            $encoded = self::BASE36_DIGITS[$value % 36] . $encoded;
            $value = intdiv($value, 36);
        }

        return $encoded;
    }
}
