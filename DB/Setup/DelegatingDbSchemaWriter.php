<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Setup;

use Magento\Framework\Setup\Declaration\Schema\Db\DbSchemaWriterInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\DbSchemaWriter as MysqlDbSchemaWriter;
use Magento\Framework\Setup\Declaration\Schema\Db\StatementAggregator;
use Morozov\PgCompat\DB\EngineResolver;

class DelegatingDbSchemaWriter implements DbSchemaWriterInterface
{
    public function __construct(
        private readonly EngineResolver $engine,
        private readonly DbSchemaWriter $postgres,
        private readonly MysqlDbSchemaWriter $mysql
    ) {
    }

    private function inner(): DbSchemaWriterInterface
    {
        return $this->engine->isPostgres() ? $this->postgres : $this->mysql;
    }

    public function createTable($tableName, $resource, array $definition, array $options)
    {
        return $this->inner()->createTable($tableName, $resource, $definition, $options);
    }

    public function dropTable($tableName, $resource)
    {
        return $this->inner()->dropTable($tableName, $resource);
    }

    public function addElement($elementName, $resource, $tableName, $elementDefinition, $elementType)
    {
        return $this->inner()->addElement($elementName, $resource, $tableName, $elementDefinition, $elementType);
    }

    public function resetAutoIncrement($tableName, $resource)
    {
        return $this->inner()->resetAutoIncrement($tableName, $resource);
    }

    public function modifyColumn($columnName, $resource, $tableName, $columnDefinition)
    {
        return $this->inner()->modifyColumn($columnName, $resource, $tableName, $columnDefinition);
    }

    public function modifyTableOption($tableName, $resource, $optionName, $optionValue)
    {
        return $this->inner()->modifyTableOption($tableName, $resource, $optionName, $optionValue);
    }

    public function dropElement($resource, $elementName, $tableName, $type)
    {
        return $this->inner()->dropElement($resource, $elementName, $tableName, $type);
    }

    public function compile(StatementAggregator $statementAggregator, $dryRun)
    {
        $this->inner()->compile($statementAggregator, $dryRun);
    }
}
