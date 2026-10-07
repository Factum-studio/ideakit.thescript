<?php

declare(strict_types=1);

namespace tests\fixtures\platform;

use modules\platform\application\dto\BrokerEnvelope;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\enum\BackgroundCommandOutcome;
use modules\platform\application\enum\OutboxWriteOutcome;
use modules\platform\application\exception\BackgroundCommandRejectedException;
use modules\telegram\application\message\TelegramUpdateReceivedPayload;
use modules\platform\application\port\IBackgroundCommandHandler;
use modules\platform\application\port\IOutboxWriter;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use yii\db\Connection;

final class PersistedTestCommandHandler implements IBackgroundCommandHandler
{
    public function __construct(
        private readonly Connection $db,
        private readonly IOutboxWriter $writer,
        private readonly string $scenario,
    ) {
    }

    public function handle(BrokerEnvelope $message): BackgroundCommandOutcome
    {
        if ($this->scenario === 'terminal') {
            throw new BackgroundCommandRejectedException();
        }
        if ($this->scenario === 'unexpected') {
            throw new RuntimeException('synthetic_handler_failure');
        }
        if ($this->scenario === 'hard_timeout') {
            while (true) {
            }
        }

        $updateId = Uuid::uuid5(Uuid::fromString($message->outboxId), 'worker-test-effect')->toString();
        $transaction = $this->db->beginTransaction();
        $receipt = $this->writer->write(new OutboxWriteIntent(
            'Telegram',
            'telegram.update.received',
            '1.0',
            'TELEGRAM_UPDATE',
            $updateId,
            new TelegramUpdateReceivedPayload($updateId),
            'worker-test.effect/' . $message->outboxId,
            $message->correlationId,
        ));
        if ($this->scenario === 'dirty') {
            return BackgroundCommandOutcome::COMPLETED;
        }
        $transaction->commit();

        if ($this->scenario === 'crash_after_commit') {
            fwrite(STDOUT, "COMMITTED\n");
            fflush(STDOUT);
            while (true) {
                usleep(100000);
            }
        }
        if ($this->scenario === 'signal_after_commit') {
            posix_kill(getmypid(), SIGTERM);
        }

        return $receipt->outcome === OutboxWriteOutcome::CREATED
            ? BackgroundCommandOutcome::COMPLETED
            : BackgroundCommandOutcome::ALREADY_COMPLETED;
    }
}
