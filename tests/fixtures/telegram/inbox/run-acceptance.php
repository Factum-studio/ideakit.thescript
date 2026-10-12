<?php

declare(strict_types=1);

use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\dto\OutboxWriteReceipt;
use modules\platform\application\port\IOutboxWriter;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\telegram\application\exception\InvalidTelegramUpdatePayloadException;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;
use tests\fixtures\platform\TestOutboxRoutes;
use tests\fixtures\telegram\inbox\InboxAcceptanceScenario;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

try {
    $mode = $argv[1] ?? '';
    $botKey = $argv[2] ?? '';
    if (!in_array($mode, ['holder', 'contender', 'invalid'], true) || preg_match('/\Aconcurrency-[a-f0-9-]{36}\z/', $botKey) !== 1) {
        throw new RuntimeException('invalid_test_scenario');
    }
    $db = InboxAcceptanceScenario::connection();
    $db->createCommand("SELECT set_config('application_name', :name, false)", [':name' => $botKey . '-' . $mode])->queryScalar();
    $writer = new DbOutboxWriter($db, TestOutboxRoutes::registry());
    if ($mode === 'holder') {
        $writer = new class ($writer) implements IOutboxWriter {
            public function __construct(private IOutboxWriter $inner)
            {
            }

            public function write(OutboxWriteIntent $intent): OutboxWriteReceipt
            {
                $receipt = $this->inner->write($intent);
                fwrite(STDOUT, "WRITTEN\n");
                fflush(STDOUT);
                $read = [STDIN];
                $write = null;
                $except = null;
                if (stream_select($read, $write, $except, 8) !== 1 || trim((string) fgets(STDIN)) !== 'COMMIT') {
                    throw new RuntimeException('controlled_rollback');
                }

                return $receipt;
            }
        };
    }
    $command = $mode === 'invalid'
        ? InboxAcceptanceScenario::command($botKey, '{"update_id":42,"text":"\u0000"}')
        : InboxAcceptanceScenario::command($botKey);
    $handler = InboxAcceptanceScenario::handler($db, $writer);
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    $read = [STDIN];
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, 8) !== 1 || trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('invalid_test_barrier');
    }
    $receipt = $handler->handle($command);
    fwrite(STDOUT, $receipt->outcome->value . ':' . $receipt->inboxId . "\n");
} catch (TelegramUpdateAcceptanceUnavailableException) {
    fwrite(STDOUT, "UNAVAILABLE\n");
} catch (InvalidTelegramUpdatePayloadException) {
    fwrite(STDOUT, "INVALID_PAYLOAD\n");
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'controlled_rollback') {
        fwrite(STDERR, "scenario_failed\n");
        exit(1);
    }
    fwrite(STDOUT, "ROLLED_BACK\n");
} catch (Throwable) {
    fwrite(STDERR, "scenario_failed\n");
    exit(1);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
