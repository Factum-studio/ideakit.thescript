<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\dto\OutboxWriteReceipt;
use modules\platform\application\port\IOutboxWriter;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use modules\telegram\application\handler\AcceptTelegramUpdateHandler;
use modules\telegram\application\port\IAcceptTelegramUpdate;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Process\Process;
use tests\fixtures\telegram\inbox\InboxAcceptanceScenario;
use Yii;
use yii\di\Container;

final class TelegramAcceptanceContainerBindingsTest extends Unit
{
    /** @dataProvider configurations */
    public function testConfiguredAcceptorCommitsAndRollsBackWithRealWriter(string $configuration): void
    {
        $originalContainer = Yii::$container;
        $originalDb = Yii::$app->get('db');
        $db = InboxAcceptanceScenario::connection();
        $bot = 'composition-' . Uuid::uuid7()->toString();
        Yii::$container = new Container();
        Yii::$app->set('db', $db);
        try {
            $config = (static fn (): array => require dirname(__DIR__, 3) . '/config/' . $configuration . '.php')();
            if ($configuration === 'web') {
                self::assertArrayHasKey('telegram', $config['modules']);
            }
            self::assertTrue(Yii::$container->has(IAcceptTelegramUpdate::class));
            $handler = Yii::$container->get(IAcceptTelegramUpdate::class);
            self::assertInstanceOf(AcceptTelegramUpdateHandler::class, $handler);
            $command = InboxAcceptanceScenario::command($bot);
            self::assertSame(TelegramUpdateAcceptanceOutcome::ACCEPTED, $handler->handle($command)->outcome);
            self::assertSame(TelegramUpdateAcceptanceOutcome::DUPLICATE, $handler->handle($command)->outcome);
            self::assertCount(1, InboxAcceptanceScenario::inbox($db, $bot));
            self::assertCount(1, InboxAcceptanceScenario::outbox($db, $bot));
            InboxAcceptanceScenario::cleanup($db, $bot);

            $failure = new RuntimeException('synthetic-storage-failure');
            $writer = new class (Yii::$container->get(IOutboxWriter::class), $failure) implements IOutboxWriter {
                public ?string $writtenId = null;

                public function __construct(private IOutboxWriter $inner, private RuntimeException $failure)
                {
                }

                public function write(OutboxWriteIntent $intent): OutboxWriteReceipt
                {
                    $this->writtenId = $this->inner->write($intent)->outboxMessageId;
                    throw $this->failure;
                }
            };
            Yii::$container->setSingleton(IOutboxWriter::class, $writer);
            try {
                Yii::$container->get(IAcceptTelegramUpdate::class)->handle($command);
                self::fail('Acceptance must not survive a writer failure.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertNotNull($writer->writtenId);
            self::assertSame([], InboxAcceptanceScenario::inbox($db, $bot));
            self::assertFalse($db->createCommand('SELECT id FROM {{%outbox_messages}} WHERE id = :id', [':id' => $writer->writtenId])->queryScalar());
        } finally {
            InboxAcceptanceScenario::cleanup($db, $bot);
            $db->close();
            Yii::$app->set('db', $originalDb);
            Yii::$container = $originalContainer;
        }
    }

    /** @dataProvider configurations */
    public function testBootstrapAndResolutionWithoutWebhookSettingsOrNetwork(string $configuration): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/telegram/inbox/bootstrap.php', $configuration]);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), 'Application bootstrap probe failed.');
        self::assertTrue($process->getErrorOutput() === '', 'Bootstrap wrote unexpected diagnostics.');
        self::assertTrue($process->getOutput() === 'acceptance_bootstrap_ok', 'Unexpected bootstrap output.');
    }

    /** @return iterable<string, array{string}> */
    public static function configurations(): iterable
    {
        yield 'web' => ['web'];
        yield 'console' => ['console'];
    }
}
