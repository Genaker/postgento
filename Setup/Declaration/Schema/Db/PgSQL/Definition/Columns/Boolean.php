<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Setup\Declaration\Schema\Db\PgSQL\Definition\Columns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbDefinitionProcessorInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Boolean as MysqlBoolean;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Nullable;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Morozov\PgCompat\DB\Dialect\ColumnDefinitionBuilder;

/**
 * Postgres-native replacement for MySQL\Definition\Columns\Boolean: MySQL's BOOLEAN is
 * really TINYINT(1); this emits Postgres' smallint directly instead of rendering
 * "TINYINT(1)" text for MysqlToPostgres::translateFragment() to regex into "smallint"
 * downstream.
 *
 * fromDefinition() is unchanged from MySQL's - it's read direction only, and
 * Morozov\PgCompat\DB\Setup\DbSchemaReader already synthesizes a MySQL-shaped
 * "definition" string for exactly this reason - so it's delegated rather than
 * reimplemented.
 */
class Boolean implements DbDefinitionProcessorInterface
{
    public function __construct(
        private readonly MysqlBoolean $mysqlProcessor,
        private readonly Nullable $nullable,
        private readonly ResourceConnection $resourceConnection,
        private readonly ColumnDefinitionBuilder $columnBuilder
    ) {
    }

    /**
     * @param \Magento\Framework\Setup\Declaration\Schema\Dto\Columns\Boolean $column
     */
    public function toDefinition(ElementInterface $column)
    {
        $nullableClause = $this->nullable->toDefinition($column);
        $sql = sprintf(
            '%s smallint %s',
            $this->resourceConnection->getConnection()->quoteIdentifier($column->getName()),
            $nullableClause
        );
        if ($column->getDefault() !== null) {
            return $sql . $this->columnBuilder->explicitDefault((int) $column->getDefault());
        }
        if ($nullableClause === 'NOT NULL') {
            return $sql . $this->columnBuilder->implicitNotNullDefault('smallint');
        }
        return $sql;
    }

    public function fromDefinition(array $data)
    {
        return $this->mysqlProcessor->fromDefinition($data);
    }
}
