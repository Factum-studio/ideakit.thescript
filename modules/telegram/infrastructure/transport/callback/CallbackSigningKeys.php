<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\callback;

use InvalidArgumentException;
use LogicException;

final class CallbackSigningKeys
{
    private function __construct(
        private readonly string $current,
        private readonly ?string $previous,
    ) {
    }

    public static function fromBase64(string $current, ?string $previous = null): self
    {
        $currentKey = self::decodeKey($current);
        $previousKey = $previous === null ? null : self::decodeKey($previous);

        if ($previousKey !== null && hash_equals($currentKey, $previousKey)) {
            throw new InvalidArgumentException('invalid_callback_key_config');
        }

        return new self($currentKey, $previousKey);
    }

    public function sign(string $canonicalMessage): string
    {
        return self::mac($canonicalMessage, $this->current);
    }

    public function verify(string $canonicalMessage, string $providedMac): bool
    {
        if (strlen($providedMac) !== 16) {
            return false;
        }

        $matchesCurrent = hash_equals(self::mac($canonicalMessage, $this->current), $providedMac);
        $matchesPrevious = $this->previous !== null
            ? hash_equals(self::mac($canonicalMessage, $this->previous), $providedMac)
            : false;

        return $matchesCurrent || $matchesPrevious;
    }

    public function __serialize(): array
    {
        throw new LogicException('callback_keys_not_serializable');
    }

    public function __debugInfo(): array
    {
        return [];
    }

    private static function decodeKey(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) < 32 || base64_encode($decoded) !== $value) {
            throw new InvalidArgumentException('invalid_callback_key_config');
        }

        return $decoded;
    }

    private static function mac(string $message, string $key): string
    {
        return substr(hash_hmac('sha256', $message, $key, true), 0, 16);
    }
}
