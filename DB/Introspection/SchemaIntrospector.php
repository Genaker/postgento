<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Introspection;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Morozov\PgCompat\DB\Dialect\TypeMapper;

/**
 * Builds the pg_catalog/information_schema queries behind describeTable()/
 * getIndexList()/getForeignKeys()/isTableExists() and shapes the rows into the same
 * associative-array format Magento expects from a MySQL DESCRIBE/SHOW INDEX.
 *
 * Kept separate from the adapter class itself, which still owns the DDL-cache glue
 * (loadDdlCache/saveDdlCache/_getTableName are protected framework methods, so that
 * caching plumbing has to stay on the adapter) - this class is where the actual
 * catalog-query construction and row-to-Magento-shape mapping lives.
 */
final class SchemaIntrospector
{
    public function __construct(private readonly TypeMapper $types)
    {
    }

    public function schemaPredicate(AdapterInterface $adapter, string $nspAlias, string $schemaName): string
    {
        return sprintf(
            '(%s.nspname = %s OR %s.oid = pg_my_temp_schema())',
            $nspAlias,
            $adapter->quote($schemaName),
            $nspAlias
        );
    }

    public function tableExists(AdapterInterface $adapter, string $tableName, string $schemaName): bool
    {
        $sql = sprintf(
            'SELECT COUNT(1) AS tbl_exists FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = %s AND c.relkind IN (\'r\', \'p\')
               AND %s',
            $adapter->quote($tableName),
            $this->schemaPredicate($adapter, 'n', $schemaName)
        );
        return (bool) $adapter->fetchOne($sql);
    }

    public function describeColumns(AdapterInterface $adapter, string $tableName, string $schemaName): array
    {
        $sql = "
            SELECT
                a.attnum,
                n.nspname,
                c.relname,
                a.attname AS colname,
                t.typname AS type,
                format_type(a.atttypid, a.atttypmod) AS complete_type,
                pg_get_expr(d.adbin, d.adrelid) AS default_value,
                a.attnotnull AS notnull,
                a.attlen AS length,
                a.attidentity AS identity,
                co.contype,
                array_to_string(co.conkey, ',') AS conkey
            FROM pg_attribute a
            JOIN pg_class c ON a.attrelid = c.oid
            JOIN pg_namespace n ON c.relnamespace = n.oid
            JOIN pg_type t ON a.atttypid = t.oid
            LEFT JOIN pg_constraint co ON co.conrelid = c.oid AND a.attnum = ANY (co.conkey) AND co.contype = 'p'
            LEFT JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
            WHERE a.attnum > 0 AND NOT a.attisdropped
              AND c.relname = {$adapter->quote($tableName)}
              AND {$this->schemaPredicate($adapter, 'n', $schemaName)}
            ORDER BY a.attnum
        ";
        $desc = [];
        foreach ($adapter->fetchAll($sql) as $row) {
            $length = null;
            if (preg_match('/character(?: varying)?\((\d+)\)/', (string) $row['complete_type'], $m)) {
                $length = (int) $m[1];
            }
            $default = $row['default_value'];
            if (is_string($default) && preg_match("/^'(.*)'::/", $default, $m)) {
                $default = $m[1];
            }
            $identity = (($row['identity'] ?? '') !== '' && ($row['identity'] ?? '') !== ' ')
                || (is_string($row['default_value'] ?? null) && str_starts_with($row['default_value'], 'nextval('));
            [$dataType] = $this->types->toMagentoType((string) $row['type'], (string) $row['complete_type']);
            $desc[$row['colname']] = [
                'SCHEMA_NAME' => $row['nspname'],
                'TABLE_NAME' => $row['relname'],
                'COLUMN_NAME' => $row['colname'],
                'COLUMN_POSITION' => (int) $row['attnum'],
                'DATA_TYPE' => $dataType,
                'DEFAULT' => $identity ? null : $default,
                'NULLABLE' => !in_array($row['notnull'], [true, 't', '1', 1, 'true'], true),
                'LENGTH' => $length,
                'SCALE' => null,
                'PRECISION' => null,
                'UNSIGNED' => null,
                'PRIMARY' => $row['contype'] === 'p',
                'PRIMARY_POSITION' => $row['contype'] === 'p' ? 1 : null,
                'IDENTITY' => $identity,
            ];
        }
        return $desc;
    }

    public function indexList(AdapterInterface $adapter, string $tableName, string $schemaName): array
    {
        $ddl = [];
        $sql = "
            SELECT
                i.relname AS key_name,
                CASE WHEN ix.indisprimary THEN 'primary'
                     WHEN ix.indisunique THEN 'unique'
                     ELSE 'index' END AS index_type,
                a.attname AS column_name
            FROM pg_class t
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord) ON true
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
            WHERE t.relkind = 'r'
              AND t.relname = {$adapter->quote($tableName)}
              AND {$this->schemaPredicate($adapter, 'n', $schemaName)}
              AND k.attnum > 0
            ORDER BY i.relname, k.ord
        ";
        foreach ($adapter->fetchAll($sql) as $row) {
            $indexType = $row['index_type'];
            $keyName = $indexType === 'primary' ? 'PRIMARY' : $row['key_name'];
            $upper = strtoupper($keyName);
            if (isset($ddl[$upper])) {
                $ddl[$upper]['COLUMNS_LIST'][] = $row['column_name'];
                $ddl[$upper]['fields'][] = $row['column_name'];
            } else {
                $ddl[$upper] = [
                    'SCHEMA_NAME' => $schemaName,
                    'TABLE_NAME' => $tableName,
                    'KEY_NAME' => $keyName,
                    'COLUMNS_LIST' => [$row['column_name']],
                    'INDEX_TYPE' => $indexType,
                    'INDEX_METHOD' => 'BTREE',
                    'type' => $indexType,
                    'fields' => [$row['column_name']],
                ];
            }
        }
        return $ddl;
    }

    public function foreignKeys(AdapterInterface $adapter, string $tableName, string $schemaName): array
    {
        $sql = "
            SELECT
                tc.constraint_name,
                kcu.column_name,
                ccu.table_name AS foreign_table,
                ccu.column_name AS foreign_column,
                rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
            JOIN information_schema.referential_constraints rc
              ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_name = {$adapter->quote($tableName)}
              AND tc.table_schema = {$adapter->quote($schemaName)}
        ";
        $ddl = [];
        foreach ($adapter->fetchAll($sql) as $row) {
            $ddl[strtoupper($row['constraint_name'])] = [
                'FK_NAME' => $row['constraint_name'],
                'SCHEMA_NAME' => $schemaName,
                'TABLE_NAME' => $tableName,
                'COLUMN_NAME' => $row['column_name'],
                'REF_SHEMA_NAME' => $schemaName,
                'REF_TABLE_NAME' => $row['foreign_table'],
                'REF_COLUMN_NAME' => $row['foreign_column'],
                'ON_DELETE' => strtoupper($row['delete_rule'] ?? ''),
            ];
        }
        return $ddl;
    }
}
