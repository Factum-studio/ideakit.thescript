<?php

declare(strict_types=1);

namespace modules\telegram\application\handler;

use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\OutboxWriteFailure;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\platform\application\exception\OutboxWriteException;
use modules\platform\application\port\IOutboxWriter;
use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\dto\TelegramInboxReservation;
use modules\telegram\application\dto\TelegramUpdateAcceptanceReceipt;
use modules\telegram\application\enum\TelegramInboxReservationOutcome;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use modules\telegram\application\exception\TelegramUpdateAcceptanceIntegrityException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\telegram\application\port\IAcceptTelegramUpdate;
use modules\telegram\application\port\ITelegramAcceptanceIdGenerator;
use modules\telegram\application\port\ITelegramInboxStore;
use modules\telegram\application\port\ITelegramInboxTransactionRunner;

final class AcceptTelegramUpdateHandler implements IAcceptTelegramUpdate
{
    public function __construct(
        private readonly ITelegramInboxStore $store,
        private readonly ITelegramInboxTransactionRunner $transactions,
        private readonly IOutboxWriter $writer,
        private readonly ITelegramAcceptanceIdGenerator $idGenerator,
    ) {
    }

    public function handle(AcceptTelegramUpdateCommand $command): TelegramUpdateAcceptanceReceipt
    {
        $reservation = $this->transactions->run(function () use ($command): TelegramInboxReservation {
            $reservation = $this->store->reserve($command);
            if ($reservation->outcome === TelegramInboxReservationOutcome::EXISTING) {
                return $reservation;
            }

            try {
                $receipt = $this->writer->write(new OutboxWriteIntent(
                    ownerModule: 'Telegram',
                    messageType: 'telegram.update.received',
                    schemaVersion: '1.0',
                    aggregateType: 'TELEGRAM_UPDATE',
                    aggregateId: $reservation->inboxId,
                    payload: new TelegramUpdateReceivedPayload($reservation->inboxId),
                    idempotencyKey: 'telegram.update.received/1.0/' . $reservation->inboxId,
                    correlationId: $this->idGenerator->generate(),
                ));
            } catch (OutboxWriteException $exception) {
                if ($exception->failure === OutboxWriteFailure::PERSISTENCE_FAILURE) {
                    throw $exception;
                }
                throw new TelegramUpdateAcceptanceIntegrityException($exception);
            }

            if ($receipt->outcome !== OutboxWriteOutcome::CREATED) {
                throw new TelegramUpdateAcceptanceIntegrityException();
            }

            return $reservation;
        });

        return new TelegramUpdateAcceptanceReceipt(
            $reservation->inboxId,
            $reservation->outcome === TelegramInboxReservationOutcome::CREATED
                ? TelegramUpdateAcceptanceOutcome::ACCEPTED
                : TelegramUpdateAcceptanceOutcome::DUPLICATE,
        );
    }
}
