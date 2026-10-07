<?php

declare(strict_types=1);

namespace modules\platform\application\message;

interface IOutboxPayloadCodec
{
    public function accepts(IOutboxPayload $payload): bool;

    /** @param array<string, mixed> $technicalFields */
    public function decode(array $technicalFields): IOutboxPayload;

    public function aggregateId(IOutboxPayload $payload): string;
}
