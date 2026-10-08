<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\db;

use PDOException;
use Throwable;
use yii\db\Exception;

final class TelegramAcceptanceDbFailure
{
    private const TRANSIENT_SQLSTATES = [
        '40001', '40P01', '55P03', '57014',
        '08001', '08003', '08006',
        '57P01', '57P02', '57P03', '53300',
    ];

    public function isTransient(Throwable $failure): bool
    {
        return in_array($this->sqlState($failure), self::TRANSIENT_SQLSTATES, true);
    }

    public function isJsonInput(Throwable $failure): bool
    {
        return in_array($this->sqlState($failure), ['22P05', '22003'], true);
    }

    private function sqlState(Throwable $failure): ?string
    {
        for ($depth = 0; $depth < 8; ++$depth) {
            if ($failure instanceof Exception || $failure instanceof PDOException) {
                $state = $failure->errorInfo[0] ?? null;
                if (is_string($state) && preg_match('/\A[0-9A-Z]{5}\z/', $state) === 1) {
                    return $state;
                }
            }
            $previous = $failure->getPrevious();
            if ($previous === null) {
                return null;
            }
            $failure = $previous;
        }

        return null;
    }
}
