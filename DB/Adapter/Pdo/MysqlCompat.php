<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Adapter\Pdo;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;

/**
 * Magento Pdo\Mysql plus the helper methods composer patches call. Plugins cannot
 * add methods invoked as $adapter->getFieldSql().
 */
class MysqlCompat extends Mysql
{
    public function getGroupConcatSql($expression, $separator = ',', $orderBy = null, $distinct = false): \Zend_Db_Expr
    {
        $sql = 'GROUP_CONCAT(' . ($distinct ? 'DISTINCT ' : '') . $expression;
        if ($orderBy !== null) {
            $sql .= ' ORDER BY ' . $orderBy;
        }
        return new \Zend_Db_Expr($sql . ' SEPARATOR ' . $this->quote($separator) . ')');
    }

    public function getFieldSql($expression, array $values): \Zend_Db_Expr
    {
        $parts = [];
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }
            $parts[] = $value;
        }
        if (!$parts) {
            return new \Zend_Db_Expr('0');
        }
        return new \Zend_Db_Expr('FIELD(' . $expression . ', ' . implode(', ', $parts) . ')');
    }

    public function createTableLike($newTableName, $originTableName)
    {
        return $this->query(sprintf(
            'CREATE TABLE %s LIKE %s',
            $this->quoteIdentifier($newTableName),
            $this->quoteIdentifier($originTableName)
        ));
    }

    public function createTemporaryTableFromSelect($name, array $indexStatements, Select $select)
    {
        $sql = sprintf(
            'CREATE TEMPORARY TABLE %s %s ENGINE=%s IGNORE (%s)',
            $this->quoteIdentifier($name),
            $indexStatements ? '(' . implode(',', $indexStatements) . ')' : '',
            $this->quoteIdentifier('innodb'),
            $select
        );
        return $this->query($sql, $select->getBind());
    }

    public function castToText($expression): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr((string) $expression);
    }

    public function castToNumeric($expression): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr('CAST(' . $expression . ' AS DECIMAL(20,6))');
    }
}
