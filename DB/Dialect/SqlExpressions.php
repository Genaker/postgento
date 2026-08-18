<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Dialect;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Statement\Parameter;
use Magento\Framework\Stdlib\DateTime;
use Morozov\PgCompat\DB\Connection\EmptyIdCondition;

/**
 * Magento AdapterInterface SQL-expression helpers, in Postgres dialect.
 * Public methods stay on the adapter; Magento calls $connection->getIfNullSql() etc.
 */
final class SqlExpressions
{
    /** @var array AdapterInterface::INTERVAL_* => Postgres INTERVAL/EXTRACT unit keyword */
    private const INTERVAL_UNITS = [
        AdapterInterface::INTERVAL_YEAR => 'YEAR',
        AdapterInterface::INTERVAL_MONTH => 'MONTH',
        AdapterInterface::INTERVAL_DAY => 'DAY',
        AdapterInterface::INTERVAL_HOUR => 'HOUR',
        AdapterInterface::INTERVAL_MINUTE => 'MINUTE',
        AdapterInterface::INTERVAL_SECOND => 'SECOND',
    ];

    public function __construct(
        private readonly DateTime $dateTime,
        private readonly EmptyIdCondition $emptyIdCondition,
    ) {
    }

    public function coerceQuoteIntoValue(string $text, mixed $value): mixed
    {
        if ($this->emptyIdCondition->isEmptyInList($text, $value)) {
            return new \Zend_Db_Expr('NULL');
        }
        return $this->emptyIdCondition->coerceEqOrGt($text, $value);
    }

    public function check($expression, $true, $false): \Zend_Db_Expr
    {
        if ($expression instanceof \Zend_Db_Expr || $expression instanceof \Zend_Db_Select) {
            $sql = sprintf('CASE WHEN (%s) THEN %s ELSE %s END', $expression, $true, $false);
        } else {
            $sql = sprintf('CASE WHEN %s THEN %s ELSE %s END', $expression, $true, $false);
        }
        return new \Zend_Db_Expr($sql);
    }

    public function ifNull($expression, $value = 0): \Zend_Db_Expr
    {
        if ($expression instanceof \Zend_Db_Expr || $expression instanceof \Zend_Db_Select) {
            $sql = sprintf('COALESCE((%s), %s)', $expression, $value);
        } else {
            $sql = sprintf('COALESCE(%s, %s)', $expression, $value);
        }
        return new \Zend_Db_Expr($sql);
    }

    public function caseSql($valueName, $casesResults, $defaultValue = null): \Zend_Db_Expr
    {
        $expression = 'CASE ' . $valueName;
        foreach ($casesResults as $case => $result) {
            $expression .= ' WHEN ' . $case . ' THEN ' . $result;
        }
        if ($defaultValue !== null) {
            $expression .= ' ELSE ' . $defaultValue;
        }
        $expression .= ' END';
        return new \Zend_Db_Expr($expression);
    }

    public function concat(AdapterInterface $adapter, array $data, $separator = null): \Zend_Db_Expr
    {
        if ($separator === null) {
            return new \Zend_Db_Expr(sprintf('(%s)', implode(' || ', $data)));
        }
        return new \Zend_Db_Expr(sprintf('CONCAT_WS(%s, %s)', $adapter->quote($separator), implode(', ', $data)));
    }

    public function length($string): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('LENGTH(%s)', $string));
    }

    public function groupConcat(AdapterInterface $adapter, $expression, $separator = ',', $orderBy = null, $distinct = false): \Zend_Db_Expr
    {
        $sql = 'string_agg(' . ($distinct ? 'DISTINCT ' : '') . $expression . '::text, ' . $adapter->quote($separator);
        if ($orderBy !== null) {
            $sql .= ' ORDER BY ' . $orderBy;
        }
        return new \Zend_Db_Expr($sql . ')');
    }

    public function field($expression, array $values): \Zend_Db_Expr
    {
        $whens = [];
        $position = 1;
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }
            $whens[] = 'WHEN ' . $value . ' THEN ' . $position++;
        }
        if (!$whens) {
            return new \Zend_Db_Expr('0');
        }
        return new \Zend_Db_Expr('(CASE ' . $expression . ' ' . implode(' ', $whens) . ' ELSE 0 END)');
    }

    public function least(array $data): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('LEAST(%s)', implode(', ', $data)));
    }

    public function greatest(array $data): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('GREATEST(%s)', implode(', ', $data)));
    }

    public function dateAdd($date, $interval, $unit): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('(%s + INTERVAL \'%s %s\')', $date, (int) $interval, $this->intervalUnit($unit)));
    }

    public function dateSub($date, $interval, $unit): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('(%s - INTERVAL \'%s %s\')', $date, (int) $interval, $this->intervalUnit($unit)));
    }

    public function dateFormat(AdapterInterface $adapter, $date, $format): \Zend_Db_Expr
    {
        $map = [
            '%Y' => 'YYYY', '%y' => 'YY',
            '%m' => 'MM', '%d' => 'DD',
            '%H' => 'HH24', '%h' => 'HH12',
            '%i' => 'MI', '%s' => 'SS',
            '%M' => 'Month', '%b' => 'Mon',
            '%D' => 'DDth', '%W' => 'Day', '%a' => 'Dy',
            '%p' => 'AM',
        ];
        return new \Zend_Db_Expr(sprintf('TO_CHAR(%s, %s)', $date, $adapter->quote(strtr($format, $map))));
    }

    public function datePart($date): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('(%s)::date', $date));
    }

    public function substring($stringExpression, $pos, $len = null): \Zend_Db_Expr
    {
        if ($len === null) {
            return new \Zend_Db_Expr(sprintf('SUBSTRING(%s, %s)', $stringExpression, $pos));
        }
        return new \Zend_Db_Expr(sprintf('SUBSTRING(%s, %s, %s)', $stringExpression, $pos, $len));
    }

    public function standardDeviation($expressionField): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('STDDEV_SAMP(%s)', $expressionField));
    }

    public function dateExtract($date, $unit): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf('EXTRACT(%s FROM %s)', $this->intervalUnit($unit), $date));
    }

    public function formatDate(AdapterInterface $adapter, $date, $includeTime = true): \Zend_Db_Expr
    {
        $date = $this->dateTime->formatDate($date, $includeTime);
        if ($date === null) {
            return new \Zend_Db_Expr('NULL');
        }
        return new \Zend_Db_Expr($adapter->quote($date));
    }

    public function prepareColumnValue(AdapterInterface $adapter, array $column, $value)
    {
        if ($value instanceof \Zend_Db_Expr || $value instanceof Parameter) {
            return $value;
        }
        if (!isset($column['DATA_TYPE'])) {
            return $value;
        }
        if ($value === null && $column['NULLABLE']) {
            return null;
        }
        switch ($column['DATA_TYPE']) {
            case 'smallint':
            case 'int':
                $value = (int) $value;
                break;
            case 'bigint':
                if (!is_int($value)) {
                    $value = sprintf('%.0f', (float) $value);
                }
                break;
            case 'decimal':
                $precision = $column['PRECISION'] ?? 10;
                $scale = $column['SCALE'] ?? 0;
                $value = (float) sprintf('%' . ($precision - $scale) . '.' . $scale . 'F', $value);
                break;
            case 'float':
                $value = (float) sprintf('%F', $value);
                break;
            case 'date':
                $value = $this->formatDate($adapter, $value, false);
                break;
            case 'datetime':
            case 'timestamp':
                $value = $this->formatDate($adapter, $value);
                break;
            case 'varchar':
            case 'mediumtext':
            case 'text':
            case 'longtext':
                $value = (string) $value;
                if ($column['NULLABLE'] && $value === '') {
                    $value = null;
                }
                break;
        }
        return $value;
    }

    public function prepareSqlCondition(AdapterInterface $adapter, $fieldName, $condition)
    {
        $conditionKeyMap = [
            'eq' => '{{fieldName}} = ?',
            'neq' => '{{fieldName}} != ?',
            'like' => '{{fieldName}} LIKE ?',
            'nlike' => '{{fieldName}} NOT LIKE ?',
            'in' => '{{fieldName}} IN(?)',
            'nin' => '{{fieldName}} NOT IN(?)',
            'is' => '{{fieldName}} IS ?',
            'notnull' => '{{fieldName}} IS NOT NULL',
            'null' => '{{fieldName}} IS NULL',
            'gt' => '{{fieldName}} > ?',
            'lt' => '{{fieldName}} < ?',
            'gteq' => '{{fieldName}} >= ?',
            'lteq' => '{{fieldName}} <= ?',
            'finset' => '? = ANY (string_to_array({{fieldName}}, \',\'))',
            'nfinset' => 'NOT (? = ANY (string_to_array({{fieldName}}, \',\')))',
            'regexp' => '{{fieldName}} ~ ?',
            'from' => '{{fieldName}} >= ?',
            'to' => '{{fieldName}} <= ?',
            'seq' => null,
            'sneq' => null,
        ];

        if (!is_array($condition)) {
            if ($condition === '') {
                $condition = $this->emptyIdCondition->coerceEqOrGt((string) $fieldName, $condition);
            }
            return $this->prepareQuotedSqlCondition(
                $adapter,
                $conditionKeyMap['eq'],
                is_int($condition) ? $condition : (string) $condition,
                $fieldName
            );
        }

        $key = key(array_intersect_key($condition, $conditionKeyMap));
        if (isset($condition['from']) || isset($condition['to'])) {
            $query = '';
            if (isset($condition['from'])) {
                $query = $this->prepareQuotedSqlCondition($adapter, $conditionKeyMap['from'], $condition['from'], $fieldName);
            }
            if (isset($condition['to'])) {
                $query .= empty($query) ? '' : ' AND ';
                $query .= $this->prepareQuotedSqlCondition($adapter, $conditionKeyMap['to'], $condition['to'], $fieldName);
            }
            return $query;
        }
        if (array_key_exists($key, $conditionKeyMap)) {
            $value = $condition[$key];
            if ($key === 'seq' || $key === 'sneq') {
                $key = $this->transformStringSqlCondition($key, $value);
            }
            if (($key === 'in' || $key === 'nin') && is_string($value)) {
                $value = explode(',', $value);
            }
            if ($key === 'in' && $this->emptyIdCondition->isEmptyInList((string) $fieldName, $value)) {
                return str_replace('{{fieldName}}', (string) $fieldName, '{{fieldName}} IN(NULL)');
            }
            if ($key === 'eq' || $key === 'gt') {
                $value = $this->emptyIdCondition->coerceEqOrGt((string) $fieldName, $value);
            }
            return $this->prepareQuotedSqlCondition($adapter, $conditionKeyMap[$key], $value, $fieldName);
        }
        $queries = [];
        foreach ($condition as $orCondition) {
            $queries[] = sprintf('(%s)', $this->prepareSqlCondition($adapter, $fieldName, $orCondition));
        }
        return sprintf('(%s)', implode(' OR ', $queries));
    }

    private function prepareQuotedSqlCondition(AdapterInterface $adapter, $text, $value, $fieldName): string
    {
        return $adapter->quoteInto(str_replace('{{fieldName}}', (string) $fieldName, (string) $text), $value);
    }

    private function transformStringSqlCondition(string $conditionKey, $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return $conditionKey === 'seq' ? 'null' : 'notnull';
        }
        return $conditionKey === 'seq' ? 'eq' : 'neq';
    }

    private function intervalUnit(string $unit): string
    {
        if (!isset(self::INTERVAL_UNITS[$unit])) {
            throw new \Zend_Db_Exception(sprintf('Undefined interval unit "%s" specified', $unit));
        }
        return self::INTERVAL_UNITS[$unit];
    }
}
