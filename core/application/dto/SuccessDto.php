<?php

declare(strict_types=1);

namespace core\application\dto;

class SuccessDto implements \JsonSerializable
{
    public function __construct(
        public mixed $data      = null,
        public string $message  = 'OK',
    ) {
    }

    public function jsonSerialize(): array
    {
        if ($this->data === null) {
            return ['message' => $this->message];
        }

        if (is_array($this->data)) {
            return $this->data;
        }

        if (is_object($this->data) && method_exists($this->data, 'jsonSerialize')) {
            return $this->data->jsonSerialize();
        }

        return ['data' => $this->data];
    }
}
