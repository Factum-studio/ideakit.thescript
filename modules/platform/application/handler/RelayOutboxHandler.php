<?php

declare(strict_types=1);

namespace modules\platform\application\handler;

use modules\platform\application\command\RelayOutboxCommand;
use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelayReceipt;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\enum\OutboxRelayError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\BrokerTransportException;
use modules\platform\application\exception\OutboxRelayException;
use modules\platform\application\policy\OutboxRetryPolicy;
use modules\platform\application\port\IBrokerPublisher;
use modules\platform\application\port\IOutboxRelayStore;
use Throwable;

final class RelayOutboxHandler
{
    public function __construct(
        private readonly IOutboxRelayStore $store,
        private readonly IBrokerPublisher $publisher,
        private readonly OutboxRelaySettings $settings,
        private readonly OutboxRetryPolicy $retryPolicy,
    ) {
    }

    /** @throws OutboxRelayException */
    public function handle(RelayOutboxCommand $command): OutboxRelayReceipt
    {
        $claimed = $delivered = $retryScheduled = $failed = $leaseLost = 0;
        $causeCode = SafeCauseCode::PERSISTENCE;

        try {
            while ($claimed < $command->limit) {
                $causeCode = SafeCauseCode::PERSISTENCE;
                $claim = $this->store->claimNext($this->settings);
                if ($claim === null) {
                    break;
                }
                $claimed++;
                $causeCode = SafeCauseCode::UNKNOWN;
                $decision = $this->decideClaim($claim);
                $causeCode = SafeCauseCode::PERSISTENCE;
                if ($decision === null || !$this->store->finish($claim, $decision)) {
                    $leaseLost++;

                    continue;
                }
                match ($decision->status) {
                    'DELIVERED' => $delivered++,
                    'RETRY_SCHEDULED' => $retryScheduled++,
                    'FAILED' => $failed++,
                    default => throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE),
                };
            }

            return new OutboxRelayReceipt($claimed, $delivered, $retryScheduled, $failed, $leaseLost);
        } catch (OutboxRelayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutboxRelayException(
                OutboxRelayError::UNEXPECTED_FAILURE,
                $exception instanceof BrokerTransportException ? null : $exception,
                $exception instanceof BrokerTransportException ? SafeCauseCode::TRANSPORT : $causeCode,
            );
        }
    }

    /** @throws OutboxRelayException */
    private function decideClaim(OutboxRelayClaim $claim): ?OutboxRelayDecision
    {
        if ($claim->rejection !== null) {
            return OutboxRelayDecision::failed($claim->rejection);
        }
        try {
            $active = $this->store->isLeaseActive($claim);
        } catch (OutboxRelayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE, $exception, SafeCauseCode::PERSISTENCE);
        }
        if (!$active) {
            return null;
        }
        $envelope = $claim->envelope;
        if ($envelope === null) {
            throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE);
        }

        try {
            $receipt = $this->publisher->publish($envelope);
        } catch (BrokerTransportException $exception) {
            return $this->retryPolicy->decide(
                $this->retryPolicy->mapTransportFailure($exception->errorCode),
                $claim->attemptNumber,
                $this->settings->maxAttempts,
            );
        } catch (Throwable $exception) {
            throw new OutboxRelayException(OutboxRelayError::UNEXPECTED_FAILURE, $exception, SafeCauseCode::TRANSPORT);
        }

        return $receipt->outboxId === $claim->outboxId
            ? OutboxRelayDecision::delivered()
            : OutboxRelayDecision::failed(OutboxRelayError::CONFIRM_MISMATCH);
    }
}
