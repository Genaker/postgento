<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Setup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\Declaration\Schema\Db\DbSchemaReaderInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\DefinitionAggregator;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;
use Morozov\PgCompat\DB\Dialect\TypeMapper;

class DbSchemaReader implements DbSchemaReaderInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DefinitionAggregator $definitionAggregator,
        private readonly TypeMapper $types
    ) {
    }

    public function getTableOptions($tableName, $resource)
    {
        return [
            'engine' => 'innodb',
            'comment' => '',
            'collation' => 'utf8mb4_general_ci',
            'charset' => 'utf8mb4',
        ];
    }

    public function readColumns($tableName, $resource)
    {
        $adapter = $this->resourceConnection->getConnection($resource);
        $schema = $this->schema($resource);
        $sql = "
            SELECT
                a.attname AS name,
                pg_get_expr(d.adbin, d.adrelid) AS default_value,
                format_type(a.atttypid, a.atttypmod) AS complete_type,
                t.typname AS pg_type,
                NOT a.attnotnull AS nullable,
                col_description(a.attrelid, a.attnum) AS comment,
                a.attidentity AS identity,
                a.attnum
            FROM pg_attribute a
            JOIN pg_class c ON a.attrelid = c.oid
            JOIN pg_namespace n ON c.relnamespace = n.oid
            JOIN pg_type t ON a.atttypid = t.oid
            LEFT JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
            WHERE a.attnum > 0
              AND NOT a.attisdropped
              AND c.relname = {$adapter->quote($tableName)}
              AND n.nspname = {$adapter->quote($schema)}
            ORDER BY a.attnum
        ";
        $columns = [];
        foreach ($adapter->fetchAll($sql) as $row) {
            $mapped = $this->mapColumn($row);
            $column = $this->definitionAggregator->fromDefinition($mapped);
            $columns[$column['name']] = $column;
        }
        return $columns;
    }

    public function readIndexes($tableName, $resource)
    {
        $indexes = [];
        foreach ($this->fetchIndexes($tableName, $resource, false) as $indexDefinition) {
            $indexDefinition['type'] = 'index';
            $index = $this->definitionAggregator->fromDefinition($indexDefinition);
            if (!isset($indexes[$index['name']])) {
                $indexes[$index['name']] = [];
            }
            $indexes[$index['name']] = array_replace_recursive($indexes[$index['name']], $index);
        }
        return $indexes;
    }

    public function readReferences($tableName, $resource)
    {
        $adapter = $this->resourceConnection->getConnection($resource);
        $schema = $this->schema($resource);
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
              AND tc.table_schema = {$adapter->quote($schema)}
        ";
        $create = 'CREATE TABLE `' . $tableName . '` (id int';
        foreach ($adapter->fetchAll($sql) as $row) {
            $onDelete = strtoupper($row['delete_rule'] ?? 'NO ACTION');
            $create .= sprintf(
                ', CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s',
                $row['constraint_name'],
                $row['column_name'],
                $row['foreign_table'],
                $row['foreign_column'],
                $onDelete
            );
        }
        $create .= ')';
        return $this->definitionAggregator->fromDefinition([
            'Create Table' => $create,
            'type' => 'reference',
        ]);
    }

    public function readConstraints($tableName, $resource)
    {
        $constraints = [];
        foreach ($this->fetchIndexes($tableName, $resource, true) as $constraintDefinition) {
            $constraintDefinition['type'] = Constraint::TYPE;
            $constraint = $this->definitionAggregator->fromDefinition($constraintDefinition);
            if (!isset($constraints[$constraint['name']])) {
                $constraints[$constraint['name']] = [];
            }
            $constraints[$constraint['name']] = array_replace_recursive($constraints[$constraint['name']], $constraint);
        }
        return $constraints;
    }

    public function readTables($resource)
    {
        $adapter = $this->resourceConnection->getConnection($resource);
        $schema = $this->schema($resource);
        return $adapter->fetchCol(
            "SELECT tablename FROM pg_tables WHERE schemaname = {$adapter->quote($schema)}"
        );
    }

    private function fetchIndexes(string $tableName, string $resource, bool $uniqueOnly): array
    {
        $adapter = $this->resourceConnection->getConnection($resource);
        $schema = $this->schema($resource);
        $sql = "
            SELECT
                i.relname AS \"Key_name\",
                ix.indisunique AS \"is_unique\",
                ix.indisprimary AS \"is_primary\",
                a.attname AS \"Column_name\",
                'BTREE' AS \"Index_type\"
            FROM pg_class t
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (ix.indkey)
            WHERE t.relkind = 'r'
              AND t.relname = {$adapter->quote($tableName)}
              AND n.nspname = {$adapter->quote($schema)}
            ORDER BY i.relname, a.attnum
        ";
        $rows = [];
        foreach ($adapter->fetchAll($sql) as $row) {
            $isPrimary = $row['is_primary'] === true || $row['is_primary'] === 't' || $row['is_primary'] === '1';
            $isUnique = $row['is_unique'] === true || $row['is_unique'] === 't' || $row['is_unique'] === '1';
            if ($uniqueOnly && !$isUnique) {
                continue;
            }
            if (!$uniqueOnly && $isUnique) {
                continue;
            }
            $row['Key_name'] = $isPrimary ? 'PRIMARY' : $row['Key_name'];
            $row['Non_unique'] = $isUnique ? 0 : 1;
            $rows[] = $row;
        }
        return $rows;
    }

    private function mapColumn(array $row): array
    {
        $pgType = strtolower((string) $row['pg_type']);
        $complete = strtolower((string) $row['complete_type']);
        [$type, $definition] = $this->types->toMagentoType($pgType, $complete);
        $identity = ($row['identity'] ?? '') !== '' && ($row['identity'] ?? '') !== ' ';
        if (!$identity && is_string($row['default_value'] ?? null) && str_starts_with($row['default_value'], 'nextval(')) {
            $identity = true;
        }
        $default = $row['default_value'];
        if (is_string($default) && preg_match("/^'(.*)'::/", $default, $m)) {
            $default = $m[1];
        }
        if (is_string($default) && str_starts_with($default, 'nextval(')) {
            $default = null;
        }
        return [
            'name' => $row['name'],
            'default' => $default,
            'type' => $type,
            'nullable' => (bool) $row['nullable'],
            'definition' => $definition,
            'extra' => $identity ? 'auto_increment' : '',
            'comment' => $row['comment'],
            'charset' => null,
            'collation' => null,
        ];
    }

    private function schema(string $resource): string
    {
        return 'public';
    }
}
