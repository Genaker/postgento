<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Dialect;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;

/**
 * Magento Select → Postgres UPDATE … FROM / DELETE … ctid SQL.
 * Returns SQL only; the adapter still runs query() when Magento expects execution.
 */
final class SelectWriteSql
{
    public function updateFromSelect(AdapterInterface $adapter, Select $select, $table): string
    {
        if (!is_array($table)) {
            $table = [$table => $table];
        }
        $keys = array_keys($table);
        $tableAlias = $keys[0];
        $tableName = $table[$keys[0]];

        $fromTables = [];
        $joinConditions = [];
        foreach ($select->getPart(Select::FROM) as $correlationName => $joinProp) {
            $joinTable = '';
            if ($joinProp['schema'] !== null) {
                $joinTable = sprintf('%s.', $adapter->quoteIdentifier($joinProp['schema']));
            }
            $joinTable .= $adapter->quoteTableAs($joinProp['tableName'], $correlationName);
            $fromTables[] = $joinTable;
            if (!empty($joinProp['joinCondition'])) {
                $joinConditions[] = '(' . $joinProp['joinCondition'] . ')';
            }
        }

        $columns = [];
        foreach ($select->getPart(Select::COLUMNS) as $columnEntry) {
            [$correlationName, $column, $alias] = $columnEntry;
            if (empty($alias)) {
                $alias = $column;
            }
            if (!$column instanceof \Zend_Db_Expr && !empty($correlationName)) {
                $column = $adapter->quoteIdentifier([$correlationName, $column]);
            }
            $columns[] = sprintf('%s = %s', $adapter->quoteIdentifier($alias), $column);
        }
        if (!$columns) {
            throw new LocalizedException(
                new \Magento\Framework\Phrase('The columns for UPDATE statement are not defined')
            );
        }

        $query = sprintf(
            'UPDATE %s SET %s',
            $adapter->quoteTableAs($tableName, $tableAlias),
            implode(', ', $columns)
        );
        if ($fromTables) {
            $query .= ' FROM ' . implode(', ', $fromTables);
        }
        $whereParts = $joinConditions;
        $wherePart = $select->getPart(Select::WHERE);
        if ($wherePart) {
            $whereParts[] = implode(' ', $wherePart);
        }
        if ($whereParts) {
            $query .= ' WHERE ' . implode(' AND ', $whereParts);
        }
        return $query;
    }

    public function deleteFromSelect(AdapterInterface $adapter, Select $select, $table): string
    {
        $correlation = null;
        $realTable = $table;
        foreach ($select->getPart(Select::FROM) as $alias => $part) {
            if (($part['tableName'] ?? null) === $table) {
                $correlation = $alias;
                break;
            }
            if ($alias === $table) {
                $correlation = $alias;
                $realTable = $part['tableName'] ?? $table;
                break;
            }
        }
        $targetRef = $adapter->quoteIdentifier($correlation ?? $table);

        $subSelect = clone $select;
        $subSelect->reset(Select::DISTINCT);
        $subSelect->reset(Select::COLUMNS);
        $subSelect->columns(new \Zend_Db_Expr($targetRef . '.ctid'));

        return sprintf(
            'DELETE FROM %s AS %s WHERE %s.ctid IN (%s)',
            $adapter->quoteIdentifier($realTable),
            $targetRef,
            $targetRef,
            $subSelect->assemble()
        );
    }
}
