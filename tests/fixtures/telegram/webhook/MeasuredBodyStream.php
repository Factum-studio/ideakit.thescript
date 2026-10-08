<?php

declare(strict_types=1);

namespace tests\fixtures\telegram\webhook;

final class MeasuredBodyStream
{
    /** @var resource|null */
    public $context;
    public static int $bytesRead = 0;
    public static bool $closed = false;
    public static bool $failAfterFirstRead = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$bytesRead = 0;
        self::$closed = false;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if (self::$failAfterFirstRead && self::$bytesRead > 0) {
            return false;
        }

        $length = min($count, 100_000 - self::$bytesRead);
        self::$bytesRead += $length;
        return str_repeat('x', $length);
    }

    public function stream_eof(): bool
    {
        return self::$bytesRead === 100_000;
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}
