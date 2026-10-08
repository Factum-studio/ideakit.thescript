<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use Ramsey\Uuid\Uuid;
use tests\fixtures\platform\PlatformTestEnvironment;
use tests\fixtures\telegram\inbox\InboxAcceptanceScenario;
use yii\db\Connection;

final class DbTelegramInboxConcurrencyTest extends Unit
{
    private Connection $db;
    private string $botKey;
    /** @var list<array{process: resource, pipes: array<int, resource>}> */
    private array $children = [];

    protected function _before(): void
    {
        $this->db = InboxAcceptanceScenario::connection();
        $this->botKey = 'concurrency-' . Uuid::uuid7()->toString();
    }

    protected function _after(): void
    {
        foreach ($this->children as $child) {
            foreach ($child['pipes'] as $pipe) {
                fclose($pipe);
            }
            if (proc_get_status($child['process'])['running']) {
                proc_terminate($child['process']);
            }
            proc_close($child['process']);
        }
        InboxAcceptanceScenario::cleanup($this->db, $this->botKey);
        $this->db->close();
    }

    /** @dataProvider resolutions */
    public function testRealConcurrentAcceptance(string $resolution): void
    {
        $holder = $this->start('holder');
        $contender = $this->start('contender');
        $this->signal($holder, 'GO');
        self::assertSame('WRITTEN', $this->line($holder));
        $this->signal($contender, 'GO');
        $this->assertContenderWaiting();
        if ($resolution === 'timeout') {
            self::assertSame('UNAVAILABLE', $this->line($contender));
            self::assertSame([], InboxAcceptanceScenario::inbox($this->db, $this->botKey));
        }
        $this->signal($holder, $resolution === 'rollback' ? 'ROLLBACK' : 'COMMIT');
        $first = $this->line($holder);
        $second = $resolution === 'timeout' ? null : $this->line($contender);
        $inbox = InboxAcceptanceScenario::inbox($this->db, $this->botKey);
        self::assertCount(1, $inbox);
        self::assertCount(1, InboxAcceptanceScenario::outbox($this->db, $this->botKey));
        if ($resolution === 'rollback') {
            self::assertSame('ROLLED_BACK', $first);
            self::assertSame('ACCEPTED:' . $inbox[0]['id'], $second);
        } else {
            self::assertSame('ACCEPTED:' . $inbox[0]['id'], $first);
            if ($second !== null) {
                self::assertSame('DUPLICATE:' . $inbox[0]['id'], $second);
            }
        }
        $repeat = InboxAcceptanceScenario::handler($this->db)->handle(InboxAcceptanceScenario::command($this->botKey));
        self::assertSame($inbox[0]['id'], $repeat->inboxId);
        self::assertSame('DUPLICATE', $repeat->outcome->value);
        $this->assertSuccessfulExit($holder);
        $this->assertSuccessfulExit($contender);
    }

    /** @return iterable<array{string}> */
    public static function resolutions(): iterable
    {
        yield ['commit'];
        yield ['rollback'];
        yield ['timeout'];
    }

    public function testUncommittedDuplicateDoesNotHideInvalidJsonInput(): void
    {
        $holder = $this->start('holder');
        $contender = $this->start('invalid');
        $this->signal($holder, 'GO');
        self::assertSame('WRITTEN', $this->line($holder));
        $this->signal($contender, 'GO');
        self::assertSame('INVALID_PAYLOAD', $this->line($contender));
        $this->signal($holder, 'COMMIT');
        self::assertStringStartsWith('ACCEPTED:', $this->line($holder));
        self::assertCount(1, InboxAcceptanceScenario::inbox($this->db, $this->botKey));
        self::assertCount(1, InboxAcceptanceScenario::outbox($this->db, $this->botKey));
        $this->assertSuccessfulExit($holder);
        $this->assertSuccessfulExit($contender);
    }

    private function assertContenderWaiting(): void
    {
        $deadline = microtime(true) + 1.5;
        do {
            $waiting = (int) $this->db->createCommand(
                "SELECT count(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'",
                [':name' => $this->botKey . '-contender'],
            )->queryScalar() === 1;
        } while (!$waiting && microtime(true) < $deadline);
        self::assertTrue($waiting, 'The competing connection must wait on the unique constraint.');
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    private function start(string $mode): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, 'tests/fixtures/telegram/inbox/run-acceptance.php', $mode, $this->botKey],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 5),
            PlatformTestEnvironment::databaseEnvironment(),
        );
        self::assertIsResource($process);
        $child = ['process' => $process, 'pipes' => $pipes];
        $this->children[] = $child;
        self::assertSame('READY', $this->line($child));

        return $child;
    }

    /** @param array{process: resource, pipes: array<int, resource>} $child */
    private function signal(array $child, string $signal): void
    {
        self::assertSame(strlen($signal) + 1, fwrite($child['pipes'][0], $signal . "\n"));
        fflush($child['pipes'][0]);
    }

    /** @param array{process: resource, pipes: array<int, resource>} $child */
    private function line(array $child): string
    {
        $read = [$child['pipes'][1]];
        $write = null;
        $except = null;
        self::assertSame(1, stream_select($read, $write, $except, 6), 'Child barrier timed out.');
        $line = fgets($child['pipes'][1]);
        self::assertIsString($line, 'Child failed before completing the scenario.');

        return trim($line);
    }

    /** @param array{process: resource, pipes: array<int, resource>} $child */
    private function assertSuccessfulExit(array $child): void
    {
        $deadline = microtime(true) + 3;
        do {
            $status = proc_get_status($child['process']);
        } while ($status['running'] && microtime(true) < $deadline);
        self::assertFalse($status['running'], 'Child must finish within its deadline.');
        self::assertSame(0, $status['exitcode']);
        self::assertTrue(stream_get_contents($child['pipes'][2]) === '', 'Child diagnostic channel must remain empty.');
    }
}
