<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use DateTimeImmutable;
use modules\telegram\application\command\AcceptTelegramUpdateCommand;
use modules\telegram\application\enum\IncomingTelegramUpdateType;
use modules\telegram\application\enum\TelegramUpdateIgnoreReason;
use modules\telegram\application\exception\InvalidTelegramUpdatePayloadException;
use modules\platform\application\dto\OutboxWriteIntent;
use modules\platform\application\dto\OutboxWriteReceipt;
use modules\platform\application\port\IOutboxWriter;
use modules\platform\infrastructure\db\DbOutboxWriter;
use modules\telegram\application\enum\TelegramUpdateAcceptanceOutcome;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use tests\fixtures\platform\TestOutboxRoutes;
use tests\fixtures\telegram\inbox\InboxAcceptanceScenario;
use yii\db\Connection;

final class DbTelegramInboxAcceptanceTest extends Unit
{
    private Connection $db;
    private string $botKey;

    protected function _before(): void
    {
        $this->db = InboxAcceptanceScenario::connection();
        $this->botKey = 'acceptance-' . Uuid::uuid7()->toString();
    }

    protected function _after(): void
    {
        InboxAcceptanceScenario::cleanup($this->db, $this->botKey);
        InboxAcceptanceScenario::cleanup($this->db, $this->botKey . '-other');
        $this->db->close();
    }

    public function testFirstAcceptanceCommitsInboxAndOutbox(): void
    {
        $command = InboxAcceptanceScenario::command($this->botKey);
        $receipt = InboxAcceptanceScenario::handler($this->db)->handle($command);
        self::assertSame(TelegramUpdateAcceptanceOutcome::ACCEPTED, $receipt->outcome);
        $inbox = InboxAcceptanceScenario::inbox($this->db, $this->botKey);
        $outbox = InboxAcceptanceScenario::outbox($this->db, $this->botKey);
        self::assertCount(1, $inbox);
        self::assertCount(1, $outbox);
        self::assertSame($receipt->inboxId, $inbox[0]['id']);
        self::assertSame($receipt->inboxId, $outbox[0]['aggregate_id']);
        self::assertCount(19, $inbox[0]);
        self::assertSame(7, Uuid::fromString($receipt->inboxId)->getVersion());
        self::assertSame($this->botKey, $inbox[0]['bot_key']);
        self::assertSame(42, (int) $inbox[0]['update_id']);
        self::assertSame('123', (string) $inbox[0]['chat_id']);
        self::assertSame('MESSAGE', $inbox[0]['update_type']);
        self::assertSame('telegram.update/1.0', $inbox[0]['payload_schema_version']);
        self::assertSame($command->payloadHash, $inbox[0]['payload_hash']);
        self::assertSame(['update_id' => 42], json_decode($inbox[0]['raw_payload'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('RECEIVED', $inbox[0]['status']);
        self::assertSame(0, (int) $inbox[0]['attempt_count']);
        foreach (['telegram_identity_profile_id', 'next_attempt_at', 'last_error_code', 'processed_at', 'locked_by', 'locked_until'] as $column) {
            self::assertNull($inbox[0][$column]);
        }
        $received = new DateTimeImmutable($inbox[0]['received_at']);
        self::assertEquals($received, new DateTimeImmutable($inbox[0]['updated_at']));
        self::assertEquals($received->modify('+30 days'), new DateTimeImmutable($inbox[0]['raw_payload_expires_at']));
        self::assertSame('critical', $outbox[0]['routing_key']);
        self::assertSame('telegram.update.received/1.0/' . $receipt->inboxId, $outbox[0]['idempotency_key']);
        self::assertSame(['update_id' => $receipt->inboxId], json_decode($outbox[0]['payload'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testFailureAfterRealWriterInsertRollsBackBothRecords(): void
    {
        $failure = new RuntimeException('synthetic_failure');
        $writer = new class (new DbOutboxWriter($this->db, TestOutboxRoutes::registry()), $failure) implements IOutboxWriter {
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
        try {
            InboxAcceptanceScenario::handler($this->db, $writer)->handle(InboxAcceptanceScenario::command($this->botKey));
            self::fail('Expected acceptance failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame([], InboxAcceptanceScenario::inbox($this->db, $this->botKey));
        self::assertSame([], InboxAcceptanceScenario::outbox($this->db, $this->botKey));
        self::assertNotNull($writer->writtenId);
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM {{%outbox_messages}} WHERE id = :id', [':id' => $writer->writtenId])->queryScalar());
    }

    public function testDuplicatesPreserveAllFieldsAndDifferentBotIsIndependent(): void
    {
        $handler = InboxAcceptanceScenario::handler($this->db);
        $first = $handler->handle(InboxAcceptanceScenario::command($this->botKey, '{ "update_id" : 42 }'));
        $inbox = InboxAcceptanceScenario::inbox($this->db, $this->botKey);
        $outbox = InboxAcceptanceScenario::outbox($this->db, $this->botKey);
        foreach (['{ "update_id" : 42 }', '{"update_id":42,"text":"changed"}', '{"update_id":42,"text":"\u0000"}'] as $body) {
            $repeat = $handler->handle(InboxAcceptanceScenario::command($this->botKey, $body));
            self::assertSame(TelegramUpdateAcceptanceOutcome::DUPLICATE, $repeat->outcome);
            self::assertSame($first->inboxId, $repeat->inboxId);
            self::assertSame($inbox, InboxAcceptanceScenario::inbox($this->db, $this->botKey));
            self::assertSame($outbox, InboxAcceptanceScenario::outbox($this->db, $this->botKey));
        }
        self::assertSame(hash('sha256', '{ "update_id" : 42 }'), $inbox[0]['payload_hash']);
        $other = $handler->handle(InboxAcceptanceScenario::command($this->botKey . '-other'));
        self::assertSame(TelegramUpdateAcceptanceOutcome::ACCEPTED, $other->outcome);
        self::assertNotSame($first->inboxId, $other->inboxId);
    }

    /** @dataProvider terminalStatuses */
    public function testTerminalDuplicateDoesNotReopenOrRestorePayload(string $status): void
    {
        $handler = InboxAcceptanceScenario::handler($this->db);
        $first = $handler->handle(InboxAcceptanceScenario::command($this->botKey));
        $this->db->createCommand()->update('{{%telegram_updates}}', [
            'status' => $status, 'raw_payload' => null, 'processed_at' => '2026-10-08T12:00:00+00:00',
            'last_error_code' => $status === 'FAILED' ? 'synthetic_failure' : null,
        ], ['id' => $first->inboxId])->execute();
        $before = InboxAcceptanceScenario::inbox($this->db, $this->botKey);
        $repeat = $handler->handle(InboxAcceptanceScenario::command($this->botKey));
        self::assertSame(TelegramUpdateAcceptanceOutcome::DUPLICATE, $repeat->outcome);
        self::assertSame($first->inboxId, $repeat->inboxId);
        self::assertSame($before, InboxAcceptanceScenario::inbox($this->db, $this->botKey));
        self::assertCount(1, InboxAcceptanceScenario::outbox($this->db, $this->botKey));
    }

    /** @return iterable<array{string}> */
    public static function terminalStatuses(): iterable
    {
        yield ['PROCESSED'];
        yield ['FAILED'];
        yield ['IGNORED'];
    }

    /** @dataProvider unrepresentablePayloads */
    public function testUnrepresentableNewJsonLeavesNoRecords(string $body): void
    {
        try {
            InboxAcceptanceScenario::handler($this->db)->handle(InboxAcceptanceScenario::command($this->botKey, $body));
            self::fail('Expected JSON storage failure.');
        } catch (InvalidTelegramUpdatePayloadException $exception) {
            self::assertSame('invalid_telegram_update_payload', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
        self::assertSame([], InboxAcceptanceScenario::inbox($this->db, $this->botKey));
        self::assertNull($this->db->getTransaction());
    }

    /** @return iterable<array{string}> */
    public static function unrepresentablePayloads(): iterable
    {
        yield ['{"update_id":42,"text":"\u0000"}'];
        yield ['{"update_id":42,"number":1e200000}'];
    }

    public function testIgnoredUpdateStillCreatesDurablePair(): void
    {
        $body = '{"update_id":42,"synthetic":true}';
        $receipt = InboxAcceptanceScenario::handler($this->db)->handle(new AcceptTelegramUpdateCommand(
            $this->botKey,
            42,
            IncomingTelegramUpdateType::UNSUPPORTED,
            null,
            TelegramUpdateIgnoreReason::UNSUPPORTED_UPDATE_TYPE,
            $body,
            hash('sha256', $body),
        ));
        self::assertSame(TelegramUpdateAcceptanceOutcome::ACCEPTED, $receipt->outcome);
        $row = InboxAcceptanceScenario::inbox($this->db, $this->botKey)[0];
        self::assertSame('RECEIVED', $row['status']);
        self::assertSame('UNSUPPORTED', $row['update_type']);
        self::assertNull($row['chat_id']);
        self::assertNull($row['telegram_identity_profile_id']);
        self::assertCount(1, InboxAcceptanceScenario::outbox($this->db, $this->botKey));
    }
}
