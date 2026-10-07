<?php

declare(strict_types=1);

namespace modules\platform\application\message;

interface IOutboxPayload
{
    /** @return array<string, string> */
    public function technicalFields(): array;
}
