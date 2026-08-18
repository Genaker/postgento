<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Dialect;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;

/**
 * Assembles CREATE TABLE SQL from Magento Table/column/index/FK DTOs.
 * AdapterInterface methods stay on the adapter and call query() with this output.
 */
final class TableSql
{
    public function __construct(
        private readonly ColumnDefinitionBuilder $columnBuilder,
        private readonly IndexDefinitionBuilder $indexBuilder,
        private readonly NameResolver $names,
    ) {
    }

    public function columnDefinition($options, $ddlType = null): string
    {
        $options = array_change_key_case($options, CASE_UPPER);
        $ddlType = $ddlType ?? ($options['TYPE'] ?? $options['COLUMN_TYPE'] ?? null);
        if (empty($ddlType)) {
            throw new \Zend_Db_Exception('Invalid column definition data');
        }
        return $this->columnBuilder->build($ddlType, $options);
    }

    public function ddlAction($action): string
    {
        return match ($action) {
            AdapterInterface::FK_ACTION_CASCADE => Table::ACTION_CASCADE,
            AdapterInterface::FK_ACTION_SET_NULL => Table::ACTION_SET_NULL,
            AdapterInterface::FK_ACTION_RESTRICT => Table::ACTION_RESTRICT,
            default => Table::ACTION_NO_ACTION,
        };
    }

    /**
     * @return array{sql: string, deferred: string[]}
     */
    public function createTable(Table $table): array
    {
        $indexes = $this->indexes($table);
        return [
            'sql' => sprintf(
                "CREATE TABLE IF NOT EXISTS %s (\n%s\n)",
                PgIdentifier::quote($table->getName()),
                implode(",\n", array_merge(
                    $this->columnsAndForeignKeys($table),
                    $indexes['inline']
                ))
            ),
            'deferred' => $indexes['deferred'],
        ];
    }

    public function createTemporaryTable(Table $table): string
    {
        return sprintf(
            "CREATE TEMPORARY TABLE %s (\n%s\n)",
            PgIdentifier::quote($table->getName()),
            implode(",\n", $this->columnsAndForeignKeys($table))
        );
    }

    /**
     * @return array{inline: string[], deferred: string[]}
     */
    private function indexes(Table $table): array
    {
        $inline = [];
        $deferred = [];
        foreach ($table->getIndexes() as $indexData) {
            $built = $this->indexBuilder->build($table->getName(), $indexData);
            if ($built['inline'] !== null) {
                $inline[] = '  ' . $built['inline'];
            } elseif ($built['deferred'] !== null) {
                $deferred[] = $built['deferred'];
            }
        }
        return ['inline' => $inline, 'deferred' => $deferred];
    }

    private function columnsAndForeignKeys(Table $table): array
    {
        return array_merge($this->columns($table), $this->foreignKeys($table));
    }

    private function columns(Table $table): array
    {
        $definition = [];
        $primary = [];
        $columns = $table->getColumns();
        if (empty($columns)) {
            throw new \Zend_Db_Exception('Table columns are not defined');
        }
        foreach ($columns as $columnData) {
            $definition[] = sprintf(
                '  %s %s',
                PgIdentifier::quote($columnData['COLUMN_NAME']),
                $this->columnDefinition($columnData)
            );
            if ($columnData['PRIMARY']) {
                $primary[$columnData['COLUMN_NAME']] = $columnData['PRIMARY_POSITION'];
            }
        }
        if ($primary) {
            asort($primary, SORT_NUMERIC);
            $definition[] = sprintf('  PRIMARY KEY (%s)', PgIdentifier::quoteList(array_keys($primary)));
        }
        return $definition;
    }

    private function foreignKeys(Table $table): array
    {
        $definition = [];
        foreach ($table->getForeignKeys() as $fkData) {
            $definition[] = sprintf(
                '  CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
                PgIdentifier::quote($this->names->constraintName($table->getName(), $fkData['FK_NAME'])),
                PgIdentifier::quote($fkData['COLUMN_NAME']),
                PgIdentifier::quote($fkData['REF_TABLE_NAME']),
                PgIdentifier::quote($fkData['REF_COLUMN_NAME']),
                $this->ddlAction($fkData['ON_DELETE'])
            );
        }
        return $definition;
    }
}
