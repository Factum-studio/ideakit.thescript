<?php

declare(strict_types=1);

namespace tests\integration\infrastructure;

use Codeception\Test\Unit;
use Yii;

final class PostgreSqlTestDatabaseTest extends Unit
{
    public function testUsesDedicatedPostgreSqlTestDatabase(): void
    {
        $databaseName = Yii::$app->db
            ->createCommand('SELECT current_database()')
            ->queryScalar();

        self::assertSame('ideakit_test', $databaseName);
        self::assertSame('pgsql', Yii::$app->db->driverName);
    }
}
