<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Setup\Declaration\Schema\Db\PgSQL\Definition\Columns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbDefinitionProcessorInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Json as MysqlJson;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Nullable;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Morozov\PgCompat\DB\Dialect\ColumnDefinitionBuilder;

/**
 * Postgres-native replacement for MySQL\Definition\Columns\Json - Postgres' binary jsonb
 * is the closer analog to MySQL's native JSON type (both validate and store parsed, not
 * raw text), so this maps to jsonb rather than plain json.
 */
class Json implements DbDefinitionProcessorInterface
{
    public function __construct(
        private readonly MysqlJson $mysqlProcessor,
        private readonly Nullable $nullable,
        private readonly ResourceConnection $resourceConnection,
        private readonly ColumnDefinitionBuilder $columnBuilder
    ) {
    }

    public function toDefinition(ElementInterface $column)
    {
        $nullableClause = $this->nullable->toDefinition($column);
        $sql = sprintf(
            '%s jsonb %s',
            $this->resourceConnection->getConnection()->quoteIdentifier($column->getName()),
            $nullableClause
        );
        if ($nullableClause === 'NOT NULL') {
            return $sql . $this->columnBuilder->implicitNotNullDefault('jsonb');
        }
        return $sql;
    }

    public function fromDefinition(array $data)
    {
        return $this->mysqlProcessor->fromDefinition($data);
    }
}
