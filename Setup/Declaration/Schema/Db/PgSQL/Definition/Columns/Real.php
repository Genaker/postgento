<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Setup\Declaration\Schema\Db\PgSQL\Definition\Columns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbDefinitionProcessorInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Nullable;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Real as MysqlReal;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Morozov\PgCompat\DB\Dialect\ColumnDefinitionBuilder;
use Morozov\PgCompat\DB\Dialect\TypeMapper;

/**
 * Postgres-native replacement for MySQL\Definition\Columns\Real (handles decimal/float/
 * double). decimal gets its precision/scale appended as numeric(p,s); float/double have
 * no Postgres equivalent with a length, so precision/scale are ignored for those exactly
 * as they're meaningless in MySQL too once the type resolves to `real`/`double
 * precision`.
 */
class Real implements DbDefinitionProcessorInterface
{
    public function __construct(
        private readonly MysqlReal $mysqlProcessor,
        private readonly Nullable $nullable,
        private readonly ResourceConnection $resourceConnection,
        private readonly ColumnDefinitionBuilder $columnBuilder,
        private readonly TypeMapper $types
    ) {
    }

    /**
     * @param \Magento\Framework\Setup\Declaration\Schema\Dto\Columns\Real $column
     */
    public function toDefinition(ElementInterface $column)
    {
        $bareType = $this->types->declarativeTypeToPostgres($column->getType());
        $pgType = $bareType === 'numeric' && ($column->getPrecision() || $column->getScale())
            ? sprintf('numeric(%d,%d)', $column->getPrecision(), $column->getScale())
            : ($bareType === 'numeric' ? 'numeric(10,0)' : $bareType);

        $nullableClause = $this->nullable->toDefinition($column);
        $sql = sprintf(
            '%s %s %s',
            $this->resourceConnection->getConnection()->quoteIdentifier($column->getName()),
            $pgType,
            $nullableClause
        );

        if ($column->getDefault() !== null) {
            return $sql . $this->columnBuilder->explicitDefault($column->getDefault());
        }
        if ($nullableClause === 'NOT NULL') {
            return $sql . $this->columnBuilder->implicitNotNullDefault($pgType);
        }
        return $sql;
    }

    public function fromDefinition(array $data)
    {
        return $this->mysqlProcessor->fromDefinition($data);
    }
}
