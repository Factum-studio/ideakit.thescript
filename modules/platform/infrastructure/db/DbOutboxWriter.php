<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\db;

use JsonException;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\dto\OutboxWriteReceipt;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\port\IOutboxWriter;
use modules\platform\application\route\OutboxRouteRegistry;
use Ramsey\Uuid\Uuid;
use yii\db\Connection;
use yii\db\Exception as DbException;

final class DbOutboxWriter implements IOutboxWriter
{
    public function __construct(
        private readonly Connection $db,
        private readonly OutboxRouteRegistry $routes,
    ) {
    }

    /** @throws OutboxWriteException */
    public function write(OutboxWriteIntent $intent): OutboxWriteReceipt
    {
        $route = $this->routes->resolve($intent);
        if ($this->db->getTransaction() === null) {
            throw new OutboxWriteException(OutboxWriteFailure::TRANSACTION_REQUIRED);
        }

        try {
            $payload = json_encode($intent->payload->technicalFields(), JSON_THROW_ON_ERROR);
            $attemptHistory = json_encode(['schema_version' => '1.0', 'attempts' => []], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OutboxWriteException(OutboxWriteFailure::PERSISTENCE_FAILURE, $exception);
        }
        if (strlen($payload) > $route->maximumPayloadBytes) {
            throw new OutboxWriteException(OutboxWriteFailure::INVALID_INTENT);
        }

        $hash = hash('sha256', $payload);
        $id = Uuid::uuid7()->toString();
        try {
            $created = $this->db->createCommand(
                <<<'SQL'
INSERT INTO {{%outbox_messages}} (
    id, owner_module, destination, routing_key, message_type, schema_version,
    aggregate_type, aggregate_id, payload, payload_hash, idempotency_key,
    status, attempt_count, attempt_history, correlation_id
) VALUES (
    :id, :owner_module, :destination, :routing_key, :message_type, :schema_version,
    :aggregate_type, :aggregate_id, CAST(:payload AS jsonb), :payload_hash, :idempotency_key,
    'PENDING', 0, CAST(:attempt_history AS jsonb), :correlation_id
)
ON CONFLICT (idempotency_key) DO NOTHING
RETURNING id
SQL,
                [
                    ':id' => $id,
                    ':owner_module' => $intent->ownerModule,
                    ':destination' => $route->destination,
                    ':routing_key' => $route->routingKey,
                    ':message_type' => $intent->messageType,
                    ':schema_version' => $intent->schemaVersion,
                    ':aggregate_type' => $intent->aggregateType,
                    ':aggregate_id' => $intent->aggregateId,
                    ':payload' => $payload,
                    ':payload_hash' => $hash,
                    ':idempotency_key' => $intent->idempotencyKey,
                    ':attempt_history' => $attemptHistory,
                    ':correlation_id' => $intent->correlationId,
                ],
            )->queryScalar();
            if (is_string($created)) {
                return new OutboxWriteReceipt($created, OutboxWriteOutcome::CREATED);
            }

            $existing = $this->db->createCommand(
                <<<'SQL'
SELECT id, owner_module, destination, routing_key, message_type, schema_version,
       aggregate_type, aggregate_id, recipient_user_id, telegram_identity_profile_id,
       chat_id, idea_publication_id, idea_version_id, payload_hash
FROM {{%outbox_messages}}
WHERE idempotency_key = :key
SQL,
                [':key' => $intent->idempotencyKey],
            )->queryOne();
        } catch (DbException $exception) {
            throw new OutboxWriteException(OutboxWriteFailure::PERSISTENCE_FAILURE, $exception);
        }

        if (!is_array($existing) || !self::sameEffect($existing, $intent, $route->destination, $route->routingKey, $hash)) {
            throw new OutboxWriteException(OutboxWriteFailure::IDEMPOTENCY_CONFLICT);
        }

        return new OutboxWriteReceipt($existing['id'], OutboxWriteOutcome::ALREADY_EXISTS);
    }

    /** @param array<string, mixed> $row */
    private static function sameEffect(
        array $row,
        OutboxWriteIntent $intent,
        string $destination,
        string $routingKey,
        string $hash,
    ): bool {
        foreach (['recipient_user_id', 'telegram_identity_profile_id', 'chat_id', 'idea_publication_id', 'idea_version_id'] as $address) {
            if ($row[$address] !== null) {
                return false;
            }
        }

        return $row['owner_module'] === $intent->ownerModule
            && $row['destination'] === $destination
            && $row['routing_key'] === $routingKey
            && $row['message_type'] === $intent->messageType
            && $row['schema_version'] === $intent->schemaVersion
            && $row['aggregate_type'] === $intent->aggregateType
            && $row['aggregate_id'] === $intent->aggregateId
            && $row['payload_hash'] === $hash;
    }
}
