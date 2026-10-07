<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use JsonException;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\route\OutboxRouteRegistry;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Ramsey\Uuid\Uuid;
use stdClass;

final class BrokerEnvelopeCodec
{
    private const FIELDS = ['outbox_id', 'message_type', 'schema_version', 'correlation_id', 'payload'];

    public function __construct(private readonly OutboxRouteRegistry $routes)
    {
    }

    /** @throws BrokerTransportException */
    public function encode(BrokerEnvelope $envelope): AMQPMessage
    {
        $route = $this->routes->find($envelope->messageType, $envelope->schemaVersion);
        if ($route === null || !$route->payloadCodec->accepts($envelope->payload)) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }
        try {
            $payload = $envelope->payload->technicalFields();
            if (strlen(json_encode($payload, JSON_THROW_ON_ERROR, 16)) > $route->maximumPayloadBytes) {
                throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
            }
            $body = json_encode([
                'outbox_id' => $envelope->outboxId,
                'message_type' => $envelope->messageType,
                'schema_version' => $envelope->schemaVersion,
                'correlation_id' => $envelope->correlationId,
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR, 16);
        } catch (JsonException) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }
        if (strlen($body) > 4096) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }

        return new AMQPMessage($body, [
            'message_id' => $envelope->outboxId,
            'correlation_id' => $envelope->correlationId,
            'content_type' => 'application/json',
            'delivery_mode' => 2,
        ]);
    }

    /** @throws BrokerTransportException */
    public function decode(AMQPMessage $message): BrokerEnvelope
    {
        if ($message->isTruncated() || strlen($message->getBody()) > 4096) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }
        try {
            $fields = json_decode($message->getBody(), false, 16, JSON_THROW_ON_ERROR);
            if (!$fields instanceof stdClass || count(get_object_vars($fields)) !== count(self::FIELDS)
                || array_diff(array_keys(get_object_vars($fields)), self::FIELDS) !== []
                || !$fields->payload instanceof stdClass
                || strlen(json_encode($fields->payload, JSON_THROW_ON_ERROR, 16)) > 1024
            ) {
                throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
            }
            foreach (['outbox_id', 'message_type', 'schema_version', 'correlation_id'] as $field) {
                if (!is_string($fields->$field)) {
                    throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
                }
            }
            $properties = $message->get_properties();
            if (array_key_exists('application_headers', $properties)) {
                $headers = $properties['application_headers'];
                if (!$headers instanceof AMQPTable) {
                    throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
                }
                foreach ($headers->getNativeData() as $name => $count) {
                    if (!in_array($name, ['x-delivery-count', 'x-acquired-count'], true)
                        || !is_int($count) || $count < 0
                    ) {
                        throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
                    }
                }
            }
            if (($properties['message_id'] ?? null) !== $fields->outbox_id
                || ($properties['correlation_id'] ?? null) !== $fields->correlation_id
                || ($properties['content_type'] ?? null) !== 'application/json'
                || ($properties['delivery_mode'] ?? null) !== 2
            ) {
                throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
            }
            self::assertEnvelopeFields($fields->outbox_id, $fields->message_type, $fields->schema_version, $fields->correlation_id);
            $route = $this->routes->find($fields->message_type, $fields->schema_version);
            if ($route === null) {
                throw new BrokerTransportException(BrokerTransportErrorCode::UNSUPPORTED_CONTRACT);
            }

            return new BrokerEnvelope(
                $fields->outbox_id,
                $fields->message_type,
                $fields->schema_version,
                $fields->correlation_id,
                $route->payloadCodec->decode(get_object_vars($fields->payload)),
            );
        } catch (JsonException | OutboxWriteException) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }
    }

    private static function assertEnvelopeFields(string $outboxId, string $messageType, string $schemaVersion, string $correlationId): void
    {
        foreach ([$outboxId, $correlationId] as $id) {
            if (!Uuid::isValid($id) || Uuid::fromString($id)->toString() !== $id) {
                throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
            }
        }
        foreach ([[$messageType, 64], [$schemaVersion, 48]] as [$value, $limit]) {
            if (!mb_check_encoding($value, 'UTF-8') || trim($value) === ''
                || mb_strlen($value, 'UTF-8') > $limit || preg_match('/[\x00-\x1f\x7f]/', $value) !== 0
            ) {
                throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
            }
        }
    }
}
