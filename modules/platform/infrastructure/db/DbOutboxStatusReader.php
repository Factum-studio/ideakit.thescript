<?php

declare(strict_types=1);

namespace modules\platform\infrastructure\db;

use modules\platform\application\dto\OutboxStatusView;
use modules\platform\application\enum\OutboxMaintenanceError;
use modules\platform\application\enum\SafeCauseCode;
use modules\platform\application\exception\OutboxMaintenanceException;
use modules\platform\application\port\IOutboxStatusReader;
use Throwable;
use yii\db\Connection;

final class DbOutboxStatusReader implements IOutboxStatusReader
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @throws OutboxMaintenanceException */
    public function getStatus(): OutboxStatusView
    {
        try {
            $row = $this->db->createCommand(
                <<<'SQL'
SELECT
    COUNT(*) FILTER (WHERE status = 'FAILED') AS failed,
    COUNT(*) FILTER (WHERE status = 'PROCESSING' AND locked_until <= clock_timestamp()) AS expired_leases,
    COUNT(*) FILTER (WHERE status = 'PENDING' OR
        (status = 'RETRY_SCHEDULED' AND next_attempt_at <= clock_timestamp())) AS due
FROM {{%outbox_messages}}
WHERE destination = :destination
SQL,
                [':destination' => 'RABBITMQ'],
            )->queryOne();
            if (!is_array($row)) {
                throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
            }

            return new OutboxStatusView(
                self::count($row['failed'] ?? null),
                self::count($row['expired_leases'] ?? null),
                self::count($row['due'] ?? null),
            );
        } catch (OutboxMaintenanceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutboxMaintenanceException(OutboxMaintenanceError::PERSISTENCE_FAILURE, $exception, SafeCauseCode::PERSISTENCE);
        }
    }

    /** @throws OutboxMaintenanceException */
    private static function count(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A(0|[1-9][0-9]*)\z/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new OutboxMaintenanceException(OutboxMaintenanceError::INVALID_STATE);
    }
}
