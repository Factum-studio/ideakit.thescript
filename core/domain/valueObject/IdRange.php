<?php

namespace core\domain\valueObject;

/**
 * Input: string
 * Пример принимаемых для парсинга форматов строк:
 * - 1
 * - 1,2,3,4
 * - 2:20
 * - 1,3:20
 * - 1,3:20,27
 */
final class IdRange
{
    private array $ids;

    public function __construct(string $input)
    {
        $this->ids = $this->parse($input);
    }

    public static function fromString(?string $input): self
    {
        return new self($input ?? '');
    }

    public static function fromArray(array $ids): self
    {
        return new self(implode(',', $ids));
    }

    public static function empty(): self
    {
        return new self('');
    }

    private function parse(string $input): array
    {
        if (trim($input) === '') {
            return [];
        }

        $result = [];
        $parts = explode(',', $input);

        foreach ($parts as $part) {
            $part = trim($part);

            if (str_contains($part, ':')) {
                [$start, $end] = explode(':', $part);

                $start = (int)$start;
                $end = (int)$end;

                if ($start > $end) {
                    throw new \InvalidArgumentException("Invalid range {$part}");
                }

                if ($start <= 0 || $end <= 0) {
                    throw new \InvalidArgumentException("Range IDs must be positive");
                }

                $result = array_merge($result, range($start, $end));
            } else {
                $value = (int)$part;

                if ($value <= 0) {
                    throw new \InvalidArgumentException("Invalid id {$part}");
                }

                $result[] = $value;
            }
        }

        sort($result, SORT_NUMERIC);

        return array_values(array_unique($result));
    }

    public function toArray(): array
    {
        return $this->ids;
    }

    public function contains(int $id): bool
    {
        return in_array($id, $this->ids, true);
    }

    public function isEmpty(): bool
    {
        return empty($this->ids);
    }

    public function count(): int
    {
        return count($this->ids);
    }

    public function getFirst(): ?int
    {
        return $this->ids[0] ?? null;
    }

    public function getLast(): ?int
    {
        return !empty($this->ids) ? $this->ids[count($this->ids) - 1] : null;
    }
}
