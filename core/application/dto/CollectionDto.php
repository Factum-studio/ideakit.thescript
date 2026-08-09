<?php

declare(strict_types=1);

namespace core\application\dto;

class CollectionDto implements \JsonSerializable
{
    public function __construct(
        public array $items,
        public ?int $total  = null,
        public ?int $page   = null,
        public ?int $limit  = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $result = ['items' => $this->items];

        if ($this->total !== null) {
            $result['_meta'] = [
                'total' => $this->total,
                'page'  => $this->page,
                'limit' => $this->limit,
                'pages' => $this->limit ? ceil($this->total / $this->limit) : null,
            ];
        }

        return $result;
    }
}
