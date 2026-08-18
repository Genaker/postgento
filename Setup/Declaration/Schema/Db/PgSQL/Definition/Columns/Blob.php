<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Setup\Declaration\Schema\Db\PgSQL\Definition\Columns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbDefinitionProcessorInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Blob as MysqlBlob;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Nullable;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Morozov\PgCompat\DB\Dialect\ColumnDefinitionBuilder;
use Morozov\PgCompat\DB\Dialect\TypeMapper;

/**
 * Postgres-native replacement for MySQL\Definition\Columns\Blob (handles text/blob/
 * mediumtext/mediumblob/longtext/longblob) - all of MySQL's size-tiered text/blob
 * variants collapse to Postgres' single unbounded text/bytea.
 */
class Blob implements DbDefinitionProcessorInterface
{
    public function __construct(
        private readonly MysqlBlob $mysqlProcessor,
        private readonly Nullable $nullable,
        private readonly ResourceConnection $resourceConnection,
        private readonly ColumnDefinitionBuilder $columnBuilder,
        private readonly TypeMapper $types
    ) {
    }

    public function toDefinition(ElementInterface $column)
    {
        $pgType = $this->types->declarativeTypeToPostgres($column->getType());
        $nullableClause = $this->nullable->toDefinition($column);
        $sql = sprintf(
            '%s %s %s',
            $this->resourceConnection->getConnection()->quoteIdentifier($column->getName()),
            $pgType,
            $nullableClause
        );

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
