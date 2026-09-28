<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\rabbitmq;

use JsonException;
use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\enum\BrokerTransportErrorCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\message\TelegramUpdateReceivedPayload;
use PhpAmqpLib\Message\AMQPMessage;
use stdClass;

final class BrokerEnvelopeCodec
{
    private const FIELDS = ['outbox_id', 'message_type', 'schema_version', 'correlation_id', 'payload'];

    /** @throws BrokerTransportException */
    public function encode(BrokerEnvelope $envelope): AMQPMessage
    {
        if (!$envelope->payload instanceof TelegramUpdateReceivedPayload) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }
        try {
            $payload = $envelope->payload->technicalFields();
            if (strlen(json_encode($payload, JSON_THROW_ON_ERROR, 16)) > 1024) {
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
                || array_keys(get_object_vars($fields->payload)) !== ['update_id']
                || !is_string($fields->payload->update_id)
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
            if (($properties['message_id'] ?? null) !== $fields->outbox_id
                || ($properties['correlation_id'] ?? null) !== $fields->correlation_id
                || ($properties['content_type'] ?? null) !== 'application/json'
                || ($properties['delivery_mode'] ?? null) !== 2
                || array_key_exists('application_headers', $properties)
            ) {
                throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
            }

            return new BrokerEnvelope(
                $fields->outbox_id,
                $fields->message_type,
                $fields->schema_version,
                $fields->correlation_id,
                new TelegramUpdateReceivedPayload($fields->payload->update_id),
            );
        } catch (JsonException | OutboxWriteException) {
            throw new BrokerTransportException(BrokerTransportErrorCode::INVALID_ENVELOPE);
        }
    }
}
