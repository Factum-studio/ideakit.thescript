<?php

declare(strict_types=1);

namespace tests\integration\modules\platform\infrastructure;

use Codeception\Test\Unit;
use modules\platform\application\enum\CriticalWorkerError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\CriticalWorkerException;
use modules\platform\infrastructure\db\DbWorkerExecutionGuard;
use RuntimeException;
use Yii;
use yii\db\Connection;
use yii\db\Transaction;

final class DbWorkerExecutionGuardTest extends Unit
{
    private ?Connection $db = null;

    protected function _after(): void
    {
        if ($this->db !== null) {
            $this->db->getTransaction()?->rollBack();
            if ($this->db->pdo?->inTransaction()) {
                $this->db->pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testUnopenedConnectionIsNotOpenedForInspectionOrClose(): void
    {
        $db = new Connection(['dsn' => 'pgsql:host=127.0.0.1;port=1;dbname=ideakit_test']);
        $guard = new DbWorkerExecutionGuard($db);
        $guard->assertClean();
        self::assertNull($db->pdo);
        $guard->close();
        self::assertNull($db->pdo);
    }

    /** @dataProvider transactionTypes */
    public function testDirtyTransactionPreventsSettlementAndCloseRollsItBack(bool $rawPdo): void
    {
        self::assertSame('test', getenv('APP_ENV'));
        $source = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $source);
        $this->db = new Connection([
            'dsn' => $source->dsn, 'username' => $source->username, 'password' => $source->password,
        ]);
        self::assertSame('pgsql', $this->db->driverName);
        self::assertSame('ideakit_test', $this->db->createCommand('SELECT current_database()')->queryScalar());
        $guard = new DbWorkerExecutionGuard($this->db);
        $guard->assertClean();
        if ($rawPdo) {
            $this->db->pdo->beginTransaction();
        } else {
            $this->db->beginTransaction();
        }
        $this->db->createCommand("SET LOCAL application_name = 'critical-worker-uncommitted-test'")->execute();
        try {
            $guard->assertClean();
            self::fail('Expected dirty execution scope.');
        } catch (CriticalWorkerException $exception) {
            self::assertSame(CriticalWorkerError::EXECUTION_SCOPE_DIRTY, $exception->error);
            self::assertSame('execution_scope_dirty', $exception->getMessage());
            self::assertSame(SafeCauseCode::WORKER_RUNTIME, $exception->causeCode);
            self::assertNull($exception->getPrevious());
        }
        $pdo = $this->db->pdo;
        $guard->close();
        self::assertFalse($pdo->inTransaction());
        self::assertNull($this->db->pdo);
        self::assertNull($this->db->getTransaction());
    }

    /** @return iterable<string, array{bool}> */
    public static function transactionTypes(): iterable
    {
        yield 'Yii transaction' => [false];
        yield 'raw PDO transaction' => [true];
    }

    public function testCleanupFailureKeepsFirstLocalCause(): void
    {
        $cause = new RuntimeException('Synthetic inspection failure.');
        $db = new class ($cause) extends Connection {
            public function __construct(private readonly RuntimeException $cause)
            {
                parent::__construct();
            }

            public function getTransaction(): ?Transaction
            {
                throw $this->cause;
            }

            public function close(): void
            {
                throw new RuntimeException('Synthetic close failure.');
            }
        };

        try {
            (new DbWorkerExecutionGuard($db))->close();
            self::fail('Expected cleanup failure.');
        } catch (CriticalWorkerException $exception) {
            self::assertSame(CriticalWorkerError::CLEANUP_FAILURE, $exception->error);
            self::assertSame($cause, $exception->getPrevious());
            self::assertSame(SafeCauseCode::WORKER_RUNTIME, $exception->causeCode);
            self::assertSame('cleanup_failure', $exception->getMessage());
        }
    }
}
