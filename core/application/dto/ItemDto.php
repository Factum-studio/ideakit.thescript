<?php

namespace core\application\dto;

class ItemDto implements \JsonSerializable
{
    public function __construct(
        public mixed $item
    ) {}

        public function jsonSerialize(): array
    {
        return ['item' => $this->item];
    }
}
