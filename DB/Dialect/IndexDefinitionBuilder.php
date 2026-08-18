<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Dialect;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Builds Postgres index/constraint DDL directly from the index DTOs on a
 * Magento\Framework\DB\Ddl\Table (Table::getIndexes()), instead of rendering a MySQL
 * "KEY x (...)" fragment first and regex-parsing it back out.
 *
 * Postgres' CREATE TABLE only accepts PRIMARY KEY / UNIQUE / CHECK / FOREIGN KEY as
 * inline column constraints - a plain "KEY"/"INDEX" has no inline form and must be a
 * separate CREATE INDEX statement. FULLTEXT has no equivalent and is dropped, matching
 * prior behavior.
 */
final class IndexDefinitionBuilder
{
    public function __construct(private readonly NameResolver $names)
    {
    }

    /**
     * @param array $indexData One entry from Table::getIndexes()
     * @return array{inline: ?string, deferred: ?string} Exactly one of the two is set.
     */
    public function build(string $tableName, array $indexData): array
    {
        $type = strtolower($indexData['TYPE'] ?? AdapterInterface::INDEX_TYPE_INDEX);
        if ($type === AdapterInterface::INDEX_TYPE_FULLTEXT) {
            return ['inline' => null, 'deferred' => null];
        }

        $columns = array_map(
            static fn (array $column) => $column['NAME'],
            $indexData['COLUMNS'] ?? []
        );
        if (!$columns) {
            return ['inline' => null, 'deferred' => null];
        }
        $quotedColumns = PgIdentifier::quoteList($columns);

        if ($type === AdapterInterface::INDEX_TYPE_PRIMARY) {
            return ['inline' => sprintf('PRIMARY KEY (%s)', $quotedColumns), 'deferred' => null];
        }

        if ($type === AdapterInterface::INDEX_TYPE_UNIQUE) {
            return ['inline' => sprintf('UNIQUE (%s)', $quotedColumns), 'deferred' => null];
        }

        $rawName = $indexData['INDEX_NAME'] ?? implode('_', $columns);
        $indexName = $this->names->relationName($rawName, $tableName);
        $sql = sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
            PgIdentifier::quote($indexName),
            PgIdentifier::quote($tableName),
            $quotedColumns
        );
        return ['inline' => null, 'deferred' => $sql];
    }
}
