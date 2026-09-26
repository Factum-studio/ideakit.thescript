<?php

declare(strict_types=1);

namespace modules\platform\application\route;

final class OutboxRoute
{
    public function __construct(
        public readonly string $destination,
        public readonly string $routingKey,
        public readonly int $maximumPayloadBytes,
    ) {
    }
}
