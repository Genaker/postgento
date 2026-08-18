<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Dialect;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Postgres' ON CONFLICT requires naming the exact unique/primary constraint the insert
 * would violate; MySQL's ON DUPLICATE KEY UPDATE / INSERT IGNORE need no such target.
 * This is the single place that infers a conflict target from the table's live index
 * list and renders the resulting ON CONFLICT clause, shared by every insert path
 * (insertOnDuplicate, INSERT ... ON DUPLICATE KEY UPDATE text, INSERT IGNORE,
 * insertFromSelect).
 */
final class UpsertBuilder
{
    /**
     * @return string[] Column names forming the narrowest unique/primary index whose
     *                   columns are all present in $cols, or [] if none matches.
     */
    public function conflictTarget(AdapterInterface $adapter, string $table, array $cols): array
    {
        $indexes = $adapter->getIndexList(trim($table, '"'));
        $colSet = array_map('strtolower', $cols);
        $primary = [];
        foreach ($indexes as $index) {
            $type = $index['INDEX_TYPE'] ?? '';
            if ($type !== 'unique' && $type !== 'primary') {
                continue;
            }
            $indexColumns = $index['COLUMNS_LIST'] ?? [];
            if (!$indexColumns || array_diff(array_map('strtolower', $indexColumns), $colSet)) {
                continue;
            }
            if ($type === 'unique') {
                return $indexColumns;
            }
            $primary = $indexColumns;
        }
        return $primary;
    }

    /**
     * @param string[] $conflictColumns
     * @param string[] $updateColumns   Columns to overwrite with EXCLUDED.*; empty means
     *                                  DO NOTHING.
     */
    public function onConflictClause(array $conflictColumns, array $updateColumns): string
    {
        if (!$conflictColumns) {
            return '';
        }
        $target = PgIdentifier::quoteList($conflictColumns);
        if (!$updateColumns) {
            return " ON CONFLICT ($target) DO NOTHING";
        }
        $assignments = [];
        foreach ($updateColumns as $column) {
            $quoted = PgIdentifier::quote($column);
            $assignments[] = $quoted . ' = EXCLUDED.' . $quoted;
        }
        return " ON CONFLICT ($target) DO UPDATE SET " . implode(', ', $assignments);
    }
}
