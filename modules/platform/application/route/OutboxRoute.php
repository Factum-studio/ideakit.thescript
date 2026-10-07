<?php

declare(strict_types=1);

namespace modules\platform\application\route;

use InvalidArgumentException;
use modules\platform\application\message\IOutboxPayloadCodec;

final class OutboxRoute
{
    public function __construct(
        public readonly string $ownerModule,
        public readonly string $messageType,
        public readonly string $schemaVersion,
        public readonly string $aggregateType,
        public readonly string $destination,
        public readonly string $routingKey,
        public readonly int $maximumPayloadBytes,
        public readonly IOutboxPayloadCodec $payloadCodec,
    ) {
        foreach ([
            [$ownerModule, 32], [$messageType, 64], [$schemaVersion, 48], [$aggregateType, 32],
            [$destination, 16], [$routingKey, 64],
        ] as [$value, $maximumLength]) {
            if ($value === '' || strlen($value) > $maximumLength
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value) !== 1
            ) {
                throw new InvalidArgumentException('outbox_route_invalid');
            }
        }
        if ($destination !== 'RABBITMQ'
            || $maximumPayloadBytes < 1 || $maximumPayloadBytes > 1024
        ) {
            throw new InvalidArgumentException('outbox_route_invalid');
        }
    }
}
