<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Dialect;

use Magento\Framework\DB\Ddl\Table;

/**
 * Single source of truth for MySQL <-> Postgres type mapping.
 *
 * Two independent callers need this decision table:
 *  - {@see ColumnDefinitionBuilder} builds DDL from Magento's structured Table/Column
 *    options (Table::TYPE_* constants), so it needs the *forward* mapping keyed by
 *    those constants.
 *  - Schema introspection (describeTable, declarative-schema readers) needs the
 *    *reverse* mapping, from a live Postgres catalog type back to the MySQL type name
 *    Magento's core code expects to see in a DESCRIBE-shaped array.
 *
 * A third, purely textual form ({@see keywordPattern}) exists only for the legacy
 * fragment-translation fallback in MysqlToPostgres, which receives already-rendered
 * MySQL SQL text (from declarative-schema Definition processors) rather than a DTO -
 * that seam cannot use the DTO-based mapping below, only a regex over type keywords.
 */
final class TypeMapper
{
    /**
     * Forward mapping: Magento DDL type constant -> bare Postgres type (no length/precision).
     */
    public function toPostgres(string $ddlType, array $options): string
    {
        return match ($ddlType) {
            Table::TYPE_BOOLEAN => 'smallint',
            Table::TYPE_SMALLINT => 'smallint',
            Table::TYPE_INTEGER => $this->isUnsigned($options) ? 'bigint' : 'integer',
            Table::TYPE_BIGINT => 'bigint',
            Table::TYPE_FLOAT => 'real',
            Table::TYPE_NUMERIC, Table::TYPE_DECIMAL => $this->numeric($options),
            Table::TYPE_DATE => 'date',
            Table::TYPE_TIMESTAMP, Table::TYPE_DATETIME => 'timestamp',
            Table::TYPE_TEXT => $this->textOrVarchar($options),
            Table::TYPE_BLOB, Table::TYPE_VARBINARY => 'bytea',
            default => throw new \Zend_Db_Exception("Unsupported column type '$ddlType'"),
        };
    }

    private function isUnsigned(array $options): bool
    {
        return !empty($options['UNSIGNED']);
    }

    private function numeric(array $options): string
    {
        $precision = 10;
        $scale = 0;
        if (!empty($options['LENGTH']) && preg_match('#^\(?(\d+),(\d+)\)?$#', (string) $options['LENGTH'], $m)) {
            $precision = (int) $m[1];
            $scale = (int) $m[2];
        } else {
            if (isset($options['PRECISION']) && is_numeric($options['PRECISION'])) {
                $precision = (int) $options['PRECISION'];
            }
            if (isset($options['SCALE']) && is_numeric($options['SCALE'])) {
                $scale = (int) $options['SCALE'];
            }
        }
        return sprintf('numeric(%d,%d)', $precision, $scale);
    }

    private function textOrVarchar(array $options): string
    {
        $length = empty($options['LENGTH']) ? Table::DEFAULT_TEXT_SIZE : (int) $options['LENGTH'];
        return $length <= 255 ? sprintf('varchar(%d)', $length) : 'text';
    }

    /**
     * Reverse mapping: a live Postgres catalog type -> [DATA_TYPE, COLUMN_TYPE] the way
     * Magento core expects them from a MySQL DESCRIBE (e.g. 'int' not 'int4').
     *
     * @return array{0: string, 1: string}
     */
    public function toMagentoType(string $pgType, string $completeType): array
    {
        $pgType = strtolower($pgType);
        $completeType = strtolower($completeType);
        return match (true) {
            $pgType === 'int2' => ['smallint', 'smallint(6)'],
            $pgType === 'int8' => ['bigint', 'bigint(20)'],
            $pgType === 'int4', $pgType === 'int' => ['int', 'int(11)'],
            $pgType === 'numeric', $pgType === 'decimal' => $this->reverseNumeric($completeType),
            $pgType === 'float4', $pgType === 'real' => ['float', 'float'],
            $pgType === 'float8', $pgType === 'double precision' => ['double', 'double'],
            $pgType === 'varchar', $pgType === 'bpchar', $pgType === 'citext' => $this->reverseVarchar($completeType),
            $pgType === 'text' => ['text', 'text'],
            $pgType === 'timestamp', $pgType === 'timestamptz' => ['timestamp', 'timestamp'],
            $pgType === 'date' => ['date', 'date'],
            $pgType === 'bytea' => ['blob', 'blob'],
            $pgType === 'json', $pgType === 'jsonb' => ['json', 'json'],
            $pgType === 'bool', $pgType === 'boolean' => ['smallint', 'smallint(6)'],
            default => ['text', 'text'],
        };
    }

    /**
     * toMagentoType()'s first tuple element is deliberately the raw MySQL-DESCRIBE-style
     * name ('int', 'varchar', 'double', 'json', ...) - correct for describeTable()'s own
     * DATA_TYPE field (real MySQL's describeTable() returns exactly these same native
     * strings, not the abstract Table::TYPE_* constants, and other core code that
     * introspects DATA_TYPE expects that). But Table::addColumn() enforces its own
     * strict Table::TYPE_* whitelist and throws "Invalid column data type" on anything
     * else - a real, live-verified failure hit via createTableByDdl() (used by e.g.
     * Catalog\Setup\Patch\Schema\EnableSegmentation to clone catalog_category_product_index
     * per store) feeding toMagentoType()'s raw output straight into addColumn() without
     * this translation step. Mirrors what Mysql::getColumnCreateByDescribe() ->
     * _getColumnTypeByDdl() does for the same describeTable()-to-addColumn() handoff on
     * the stock MySQL adapter.
     */
    public function ddlTypeForRawType(string $rawType): string
    {
        return match ($rawType) {
            'int' => Table::TYPE_INTEGER,
            'double' => Table::TYPE_FLOAT,
            'varchar' => Table::TYPE_TEXT,
            'json' => Table::TYPE_TEXT,
            default => $rawType,
        };
    }

    private function reverseNumeric(string $completeType): array
    {
        if (preg_match('/numeric\((\d+),(\d+)\)/', $completeType, $m)) {
            return ['decimal', sprintf('decimal(%d,%d)', $m[1], $m[2])];
        }
        return ['decimal', 'decimal(10,0)'];
    }

    private function reverseVarchar(string $completeType): array
    {
        if (preg_match('/\((\d+)\)/', $completeType, $m)) {
            return ['varchar', 'varchar(' . $m[1] . ')'];
        }
        return ['text', 'text'];
    }

    /**
     * Forward mapping: declarative-schema column type key (Dto\Column::getType(), also
     * DefinitionAggregator's dispatch key - 'int', 'bigint', 'decimal', 'text', ...) ->
     * bare Postgres type. Precision/scale/length are a separate concern (Real/
     * StringBinary each append their own), and 'int' widens to bigint when unsigned for
     * the same reason the Table-DDL forward mapping does: MySQL's unsigned int range
     * doesn't fit Postgres' signed integer.
     */
    public function declarativeTypeToPostgres(string $type, bool $unsigned = false): string
    {
        return match ($type) {
            'boolean' => 'smallint',
            'smallint', 'tinyint' => 'smallint',
            'int' => $unsigned ? 'bigint' : 'integer',
            'bigint' => 'bigint',
            'decimal' => 'numeric',
            'float' => 'real',
            'double' => 'double precision',
            'text', 'mediumtext', 'longtext' => 'text',
            'blob', 'mediumblob', 'longblob' => 'bytea',
            'datetime', 'timestamp' => 'timestamp',
            'date' => 'date',
            'char' => 'char',
            'varchar' => 'varchar',
            'binary', 'varbinary' => 'bytea',
            'json' => 'jsonb',
            default => throw new \Zend_Db_Exception("Unsupported declarative-schema column type '$type'"),
        };
    }

    /**
     * Regex replacements applied to raw MySQL SQL text arriving from seams that only hand
     * us a pre-rendered string (declarative-schema Definition processors, MODIFY COLUMN
     * fragments). Ordered: longer/more specific patterns first.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function keywordReplacements(): array
    {
        return [
            ['/\bBIGINT\s+UNSIGNED\b(\s*\(\s*\d+\s*\))?/i', 'bigint'],
            ['/\bINT(?:EGER)?\s+UNSIGNED\b(\s*\(\s*\d+\s*\))?/i', 'bigint'],
            ['/\s+UNSIGNED\b/i', ''],
            ['/\bTINYINT\s*\(\s*1\s*\)/i', 'smallint'],
            ['/\bTINYINT\b(\s*\(\s*\d+\s*\))?/i', 'smallint'],
            ['/\bMEDIUMINT\b(\s*\(\s*\d+\s*\))?/i', 'integer'],
            ['/\bBIGINT\b(\s*\(\s*\d+\s*\))?/i', 'bigint'],
            ['/\bINT\b(\s*\(\s*\d+\s*\))?/i', 'integer'],
            ['/\bINTEGER\s*\(\s*\d+\s*\)/i', 'integer'],
            ['/\bSMALLINT\b(\s*\(\s*\d+\s*\))?/i', 'smallint'],
            ['/\bDOUBLE(\s+PRECISION)?\b/i', 'double precision'],
            ['/\bFLOAT\b(\s*\(\s*\d+\s*(,\s*\d+\s*)?\))?/i', 'real'],
            ['/\bDECIMAL\b/i', 'numeric'],
            ['/\bDATETIME\b/i', 'timestamp'],
            ['/\bLONGTEXT\b|\bMEDIUMTEXT\b|\bTINYTEXT\b/i', 'text'],
            ['/\bLONGBLOB\b|\bMEDIUMBLOB\b|\bBLOB\b|\bVARBINARY\s*\(\s*\d+\s*\)|\bBINARY\s*\(\s*\d+\s*\)/i', 'bytea'],
            ['/\bJSON\b/i', 'jsonb'],
            ['/\bBOOLEAN\b|\bbool\b/i', 'smallint'],
        ];
    }
}
