<?php

declare(strict_types=1);

namespace modules\platform\application\dto;

use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\IOutboxPayload;
use Ramsey\Uuid\Uuid;

final class OutboxWriteIntent
{
    /** @throws OutboxWriteException */
    public function __construct(
        public readonly string $ownerModule,
        public readonly string $messageType,
        public readonly string $schemaVersion,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly IOutboxPayload $payload,
        public readonly string $idempotencyKey,
        public readonly string $correlationId,
    ) {
        foreach ([
            [$ownerModule, 32],
            [$messageType, 64],
            [$schemaVersion, 48],
            [$aggregateType, 32],
            [$idempotencyKey, 200],
        ] as [$value, $maximumCharacters]) {
            if (
                !mb_check_encoding($value, 'UTF-8')
                || trim($value) === ''
                || mb_strlen($value, 'UTF-8') > $maximumCharacters
            ) {
                throw new OutboxWriteException(OutboxWriteFailure::INVALID_INTENT);
            }
        }

        foreach ([$aggregateId, $correlationId] as $uuid) {
            if (!Uuid::isValid($uuid) || Uuid::fromString($uuid)->toString() !== $uuid) {
                throw new OutboxWriteException(OutboxWriteFailure::INVALID_INTENT);
            }
        }
    }
}
