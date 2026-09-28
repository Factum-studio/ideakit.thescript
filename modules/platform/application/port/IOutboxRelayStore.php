<?php

declare(strict_types=1);

namespace modules\platform\application\port;

use modules\platform\application\dto\OutboxRelayClaim;
use modules\platform\application\dto\OutboxRelayDecision;
use modules\platform\application\dto\OutboxRelaySettings;
use modules\platform\application\exception\OutboxRelayException;

interface IOutboxRelayStore
{
    /**
     * Returns only after committing the claim; an active caller transaction is rejected.
     *
     * @throws OutboxRelayException
     */
    public function claimNext(OutboxRelaySettings $settings): ?OutboxRelayClaim;

    /** @throws OutboxRelayException */
    public function isLeaseActive(OutboxRelayClaim $claim): bool;

    /**
     * Commits only while the token and lease still match; false means ownership was lost.
     * An active caller transaction is rejected.
     *
     * @throws OutboxRelayException
     */
    public function finish(OutboxRelayClaim $claim, OutboxRelayDecision $decision): bool;
}
