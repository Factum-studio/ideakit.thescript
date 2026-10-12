<?php

declare(strict_types=1);

namespace tests\fixtures\telegram\webhook;

use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\dto\TelegramUpdateAcceptanceReceipt;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use modules\telegram\application\port\IAcceptTelegramUpdate;
use Throwable;

final class RecordingTelegramUpdateAcceptor implements IAcceptTelegramUpdate
{
    public int $calls = 0;
    public ?AcceptTelegramUpdateCommand $command = null;

    public function __construct(
        private readonly TelegramUpdateAcceptanceOutcome $outcome = TelegramUpdateAcceptanceOutcome::ACCEPTED,
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function handle(AcceptTelegramUpdateCommand $command): TelegramUpdateAcceptanceReceipt
    {
        ++$this->calls;
        $this->command = $command;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new TelegramUpdateAcceptanceReceipt('019f8000-0000-7000-8000-000000000001', $this->outcome);
    }
}
