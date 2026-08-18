<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Connection;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Morozov\PgCompat\DB\Dialect\UpsertBuilder;

/**
 * Remaining query-text translations that still need a live connection:
 * SHOW TABLES/TRIGGERS/CREATE (catalog lookup), ON DUPLICATE KEY fallback
 * (known sites use insertOnDuplicate/insertFromSelect; raw text still rewritten).
 * SHOW VARIABLES is patched at the three Magento call sites, not rewritten here.
 * Empty *_id = '' from Select::where() is coerced in quoteInto()/prepareSqlCondition().
 */
final class QueryRewriter
{
    public function __construct(private readonly UpsertBuilder $upsertBuilder)
    {
    }

    public function rewrite(string $sql, AdapterInterface $adapter): string
    {
        $sql = $this->rewriteMysqlShow($sql, $adapter);
        return $this->rewriteOnDuplicateKey($sql, $adapter);
    }

    private function rewriteMysqlShow(string $sql, AdapterInterface $adapter): string
    {
        if (preg_match('/^\s*SHOW TABLES\s*$/i', $sql)) {
            return "SELECT tablename FROM pg_tables WHERE schemaname = 'public'";
        }
        if (preg_match("/SHOW TABLES LIKE ['\"]([^'\"]+)['\"]/i", $sql, $m)) {
            return 'SELECT tablename FROM pg_tables WHERE schemaname = \'public\' AND tablename LIKE '
                . $adapter->quote($m[1]);
        }
        if (preg_match('/FROM\s+"information_schema"\."TRIGGERS"/i', $sql)) {
            return 'SELECT t.tgname AS "TRIGGER_NAME", p.prosrc AS "ACTION_STATEMENT", c.relname AS "EVENT_OBJECT_TABLE"
                FROM pg_trigger t
                JOIN pg_class c ON c.oid = t.tgrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_proc p ON p.oid = t.tgfoid
                WHERE NOT t.tgisinternal AND n.nspname = \'public\'';
        }
        if (preg_match("/SHOW TRIGGERS LIKE ['\"]([^'\"]+)['\"]/i", $sql, $m)) {
            return 'SELECT t.tgname AS "Trigger", c.relname AS "Table"
                FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                WHERE NOT t.tgisinternal AND c.relname LIKE ' . $adapter->quote($m[1]);
        }
        if (preg_match('/SHOW CREATE TABLE\s+(\S+)/i', $sql, $m)) {
            $table = trim($m[1], ';"\'`');
            return 'SELECT ' . $adapter->quote($table) . ' AS "Table", '
                . $adapter->quote('CREATE TABLE ' . $table) . ' AS "Create Table"';
        }
        if (preg_match('/SHOW CREATE TRIGGER\s+\S+/i', $sql)) {
            return 'SELECT \'SELECT 1\' AS "SQL Original Statement"';
        }
        if (preg_match('/^\s*DROP TRIGGER IF EXISTS\s+("?[A-Za-z0-9_]+"?)\s*$/i', $sql, $m)) {
            $name = trim($m[1], '"');
            $table = $adapter->fetchOne(
                'SELECT c.relname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                 WHERE t.tgname = ' . $adapter->quote($name) . ' AND NOT t.tgisinternal'
            );
            if ($table) {
                return sprintf(
                    'DROP TRIGGER IF EXISTS %s ON %s',
                    $adapter->quoteIdentifier($name),
                    $adapter->quoteIdentifier($table)
                );
            }
            return 'SELECT 1';
        }
        return $sql;
    }

    private function rewriteOnDuplicateKey(string $sql, AdapterInterface $adapter): string
    {
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') === false) {
            return $sql;
        }
        if (!preg_match('/^\s*INSERT\s+INTO\s+"?([A-Za-z0-9_]+)"?\s*\(([^)]+)\)/is', $sql, $m)) {
            return preg_replace('/\s+ON DUPLICATE KEY UPDATE.+$/is', '', $sql);
        }
        $cols = array_map(static fn (string $col) => trim($col, " \t\"'`"), explode(',', $m[2]));
        $conflict = $this->upsertBuilder->conflictTarget($adapter, $m[1], $cols);
        $sql = preg_replace('/\s+ON DUPLICATE KEY UPDATE.+$/is', '', $sql);
        if (!$conflict) {
            return $sql;
        }
        $conflictLower = array_map('strtolower', $conflict);
        $updates = array_values(array_filter(
            $cols,
            static fn (string $col) => !in_array(strtolower($col), $conflictLower, true)
        ));
        return $sql . $this->upsertBuilder->onConflictClause($conflict, $updates);
    }
}
