<?php

declare(strict_types=1);

namespace tests\functional\modules\telegram;

use Codeception\Example;
use Codeception\Module\Yii2;
use Closure;
use FunctionalTester;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\dto\OutboxWriteReceipt;
use modules\platform\application\port\IOutboxWriter;
use modules\telegram\application\exception\TelegramUpdateAcceptanceUnavailableException;
use modules\telegram\application\handler\AcceptTelegramUpdateHandler;
use modules\telegram\application\port\IAcceptTelegramUpdate;
use modules\telegram\infrastructure\config\TelegramWebhookConfig;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use tests\fixtures\telegram\inbox\InboxAcceptanceScenario;
use Yii;
use yii\base\Application;
use yii\db\Connection;
use yii\di\Container;

final class DurableWebhookCest
{
    private Yii2 $framework;
    private Container $originalContainer;
    private Connection $db;
    private string $bot;
    private array $server;
    private int $logOffset;
    private Closure $enableLogging;

    public function _inject(Yii2 $framework): void
    {
        $this->framework = $framework;
    }

    public function _before(): void
    {
        $this->server = $_SERVER;
        $this->originalContainer = Yii::$container;
        Yii::$container = clone Yii::$container;
        $this->db = Yii::$app->get('db');
        InboxAcceptanceScenario::assertDatabase($this->db);
        $this->bot = 'http-' . Uuid::uuid7()->toString();
        // Reload production factories so no resolved writer from a prior application is retained.
        require dirname(__DIR__, 4) . '/config/container.php';
        Yii::$container->set(TelegramWebhookConfig::class, fn () => new TelegramWebhookConfig($this->bot, 'synthetic-durable-secret'));
        Yii::$app->getLog()->getLogger()->flush(true);
        $log = $this->log();
        $this->logOffset = strlen($log);
        // Codeception disables targets before dispatch; exercise the real boundary logger.
        $this->enableLogging = static function (): void {
            foreach (Yii::$app->getLog()->targets as $target) {
                $target->enabled = true;
            }
        };
        Yii::$app->on(Application::EVENT_BEFORE_REQUEST, $this->enableLogging);
    }

    public function _after(): void
    {
        try {
            InboxAcceptanceScenario::cleanup($this->db, $this->bot);
        } finally {
            Yii::$app->off(Application::EVENT_BEFORE_REQUEST, $this->enableLogging);
            Yii::$container = $this->originalContainer;
            $_SERVER = $this->server;
        }
    }

    public function firstReceiptAndDuplicateAreDurableWithoutCreatingUsers(FunctionalTester $I): void
    {
        $before = $this->businessCounts();
        $I->assertInstanceOf(AcceptTelegramUpdateHandler::class, Yii::$container->get(IAcceptTelegramUpdate::class));
        $I->assertSame('{"ok":true}', $this->request('{"update_id":42}'));
        $I->assertSame(200, Yii::$app->response->statusCode);
        $observer = InboxAcceptanceScenario::connection();
        try {
            $inbox = InboxAcceptanceScenario::inbox($observer, $this->bot);
            $outbox = InboxAcceptanceScenario::outbox($observer, $this->bot);
            $I->assertCount(1, $inbox);
            $I->assertCount(1, $outbox);
            $I->assertSame('RECEIVED', $inbox[0]['status']);
            $I->assertNull($inbox[0]['chat_id']);
            $I->assertSame('{"ok":true}', $this->request('{"update_id":42,"extra":"changed"}'));
            $I->assertSame($inbox, InboxAcceptanceScenario::inbox($observer, $this->bot));
            $I->assertSame($outbox, InboxAcceptanceScenario::outbox($observer, $this->bot));
        } finally {
            $observer->close();
        }
        $I->assertSame($before, $this->businessCounts());
        $I->assertNull(Yii::$app->user->getIdentity(false));
        $I->assertFalse(Yii::$app->has('session', true));
    }

    public function unrepresentableJsonIsAClientErrorWithoutDurableEffect(FunctionalTester $I): void
    {
        $output = $this->request('{"update_id":42,"extra":"synthetic-private-body\\u0000"}');
        $this->assertFailure($I, $output, 400, 'webhook_invalid_update');
    }

    /**
     * @param Example&iterable<string, bool|int|string> $case
     * @dataProvider failureCases
     */
    public function writerFailuresRollbackAndRemainSafe(FunctionalTester $I, Example $case): void
    {
        $writer = new class (Yii::$container->get(IOutboxWriter::class), $case['transient']) implements IOutboxWriter {
            public function __construct(private IOutboxWriter $inner, private bool $transient)
            {
            }

            public function write(OutboxWriteIntent $intent): OutboxWriteReceipt
            {
                $this->inner->write($intent);
                $failure = new RuntimeException('synthetic-private-SQL', 0, new RuntimeException('synthetic-private-credentials'));
                throw $this->transient ? new TelegramUpdateAcceptanceUnavailableException($failure) : $failure;
            }
        };
        Yii::$container->setSingleton(IOutboxWriter::class, $writer);
        $this->assertFailure($I, $this->request('{"update_id":42,"extra":"synthetic-private-body"}'), $case['status'], $case['reason']);
    }

    protected function failureCases(): array
    {
        return [
            ['transient' => true, 'status' => 503, 'reason' => 'webhook_unavailable'],
            ['transient' => false, 'status' => 500, 'reason' => 'webhook_internal_error'],
        ];
    }

    private function assertFailure(FunctionalTester $I, string $output, int $status, string $reason): void
    {
        $I->assertSame($status, Yii::$app->response->statusCode);
        $I->assertSame(['error' => ['code' => $status, 'message' => $reason]], json_decode($output, true, 8, JSON_THROW_ON_ERROR));
        $I->assertSame([], InboxAcceptanceScenario::inbox($this->db, $this->bot));
        $I->assertSame([], InboxAcceptanceScenario::outbox($this->db, $this->bot));
        Yii::$app->getLog()->getLogger()->flush(true);
        $log = substr($this->log(), $this->logOffset);
        $I->assertSame(1, substr_count($log, 'sensitive_request.failed'));
        foreach (['synthetic-private', 'synthetic-durable-secret', 'INSERT INTO', 'previous', 'Stack trace'] as $marker) {
            $I->assertFalse(str_contains($log . $output, $marker), 'Sensitive diagnostics must not escape.');
        }
    }

    private function request(string $body): string
    {
        return (string) $this->framework->_request('POST', '/telegram/webhook', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'synthetic-durable-secret',
        ], $body);
    }

    private function log(): string
    {
        $path = Yii::getAlias('@runtime/logs/telegram-webhook-test.log');
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function businessCounts(): array
    {
        $counts = $this->db->createCommand('SELECT (SELECT count(*) FROM {{%user}}) AS users, (SELECT count(*) FROM {{%user_identity}}) AS identities, (SELECT count(*) FROM {{%telegram_identity_profiles}}) AS profiles, (SELECT count(*) FROM {{%telegram_bot_sessions}}) AS sessions')->queryOne();
        if ($counts === false) {
            throw new RuntimeException('test_state_unavailable');
        }
        return $counts;
    }
}
