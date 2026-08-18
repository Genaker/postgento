<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Connection;

use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\DB\Adapter\TableNotFoundException;

/**
 * Magento's retry/lock-wait/deadlock handling (transaction retries, indexer batching)
 * matches on these specific exception types, not on the raw driver error - so a Postgres
 * SQLSTATE has to be mapped to the same typed exceptions the MySQL adapter would throw.
 */
final class ExceptionMapper
{
    private const SQLSTATE_MAP = [
        '23505' => DuplicateException::class,
        '40P01' => DeadlockException::class,
        '55P03' => LockWaitException::class,
        '42P01' => TableNotFoundException::class,
        '08006' => ConnectionException::class,
        '57P01' => ConnectionException::class,
    ];

    public function map(\Exception $e): \Exception
    {
        $previous = $e->getPrevious();
        $pdo = $e instanceof \PDOException ? $e : ($previous instanceof \PDOException ? $previous : null);
        if (!$pdo || empty($pdo->errorInfo[0])) {
            return $e;
        }
        $class = self::SQLSTATE_MAP[$pdo->errorInfo[0]] ?? null;
        if ($class === null) {
            return $e;
        }
        return new $class($e->getMessage(), 0, $e);
    }
}
