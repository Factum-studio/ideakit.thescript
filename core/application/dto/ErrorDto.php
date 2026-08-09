<?php

declare(strict_types=1);

namespace core\application\dto;

class ErrorDto implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $message,
        public int $code        = 400,
        public array $details   = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        $result = [
            'error' => [
                'code'      => $this->code,
                'message'   => $this->message,
            ],
        ];
        if (!empty($this->details)) {
            $result['error']['details'] = $this->details;
        }
        return $result;
    }
}
