<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\db;

use DateTimeZone;
use InvalidArgumentException;
use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\dto\TelegramInboxReservation;
use modules\telegram\application\enum\TelegramInboxReservationOutcome;
use modules\telegram\application\exception\InvalidTelegramUpdatePayloadException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceIntegrityException;
use modules\telegram\application\port\ITelegramAcceptanceClock;
use modules\telegram\application\port\ITelegramAcceptanceIdGenerator;
use modules\telegram\application\port\ITelegramInboxStore;
use Ramsey\Uuid\Uuid;
use yii\db\Connection;
use yii\db\Exception;

final class DbTelegramInboxStore implements ITelegramInboxStore
{
    public function __construct(
        private readonly Connection $db,
        private readonly ITelegramAcceptanceClock $clock,
        private readonly ITelegramAcceptanceIdGenerator $ids,
        private readonly TelegramAcceptanceDbFailure $failures = new TelegramAcceptanceDbFailure(),
    ) {
    }

    public function reserve(AcceptTelegramUpdateCommand $command): TelegramInboxReservation
    {
        if ($this->db->getTransaction() === null || !$this->db->pdo->inTransaction()) {
            throw new TelegramUpdateAcceptanceIntegrityException();
        }
        $existing = $this->findId($command);
        if ($existing !== false) {
            return $this->reservation($existing, TelegramInboxReservationOutcome::EXISTING);
        }

        $id = $this->ids->generate();
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $expires = $now->modify('+30 days');
        if (!Uuid::isValid($id) || Uuid::fromString($id)->toString() !== $id || Uuid::fromString($id)->getVersion() !== 7
            || (int) $now->format('Y') < 1 || (int) $expires->format('Y') > 9999) {
            throw new TelegramUpdateAcceptanceIntegrityException();
        }

        try {
            $created = $this->db->createCommand(
                <<<'SQL'
INSERT INTO {{%telegram_updates}} (
    id, bot_key, update_id, telegram_identity_profile_id, chat_id,
    update_type, payload_schema_version, payload_hash, raw_payload,
    status, attempt_count, next_attempt_at, last_error_code,
    received_at, processed_at, raw_payload_expires_at,
    locked_by, locked_until, updated_at
) VALUES (
    :id, :bot_key, :update_id, NULL, :chat_id,
    :update_type, :schema_version, :payload_hash, CAST(:raw_payload AS jsonb),
    'RECEIVED', 0, NULL, NULL,
    :received_at, NULL, :expires_at,
    NULL, NULL, :updated_at
)
ON CONFLICT ON CONSTRAINT uq_telegram_updates_bot_key_update_id
DO NOTHING
RETURNING id
SQL,
                [
                    ':id' => $id,
                    ':bot_key' => $command->botKey,
                    ':update_id' => $command->telegramUpdateId,
                    ':chat_id' => $command->chatId,
                    ':update_type' => $command->updateType->value,
                    ':schema_version' => $command->payloadSchemaVersion,
                    ':payload_hash' => $command->payloadHash,
                    ':raw_payload' => $command->rawPayload,
                    ':received_at' => $now->format('Y-m-d\TH:i:s.uP'),
                    ':expires_at' => $expires->format('Y-m-d\TH:i:s.uP'),
                    ':updated_at' => $now->format('Y-m-d\TH:i:s.uP'),
                ],
            )->queryScalar();
        } catch (Exception $exception) {
            if ($this->failures->isJsonInput($exception)) {
                throw new InvalidTelegramUpdatePayloadException($exception);
            }
            throw $exception;
        }

        if ($created !== false) {
            return $this->reservation($created, TelegramInboxReservationOutcome::CREATED);
        }

        // A separate READ COMMITTED statement can see the winner of the unique-key race.
        return $this->reservation($this->findId($command), TelegramInboxReservationOutcome::EXISTING);
    }

    private function findId(AcceptTelegramUpdateCommand $command): mixed
    {
        return $this->db->createCommand(
            'SELECT id FROM {{%telegram_updates}} WHERE bot_key = :bot_key AND update_id = :update_id',
            [':bot_key' => $command->botKey, ':update_id' => $command->telegramUpdateId],
        )->queryScalar();
    }

    private function reservation(mixed $id, TelegramInboxReservationOutcome $outcome): TelegramInboxReservation
    {
        if (!is_string($id)) {
            throw new TelegramUpdateAcceptanceIntegrityException();
        }
        try {
            return new TelegramInboxReservation($id, $outcome);
        } catch (InvalidArgumentException $exception) {
            throw new TelegramUpdateAcceptanceIntegrityException($exception);
        }
    }
}
