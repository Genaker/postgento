<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Setup\Declaration\Schema\Db\PgSQL\Definition\Columns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbDefinitionProcessorInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Nullable;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\StringBinary as MysqlStringBinary;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Morozov\PgCompat\DB\Dialect\ColumnDefinitionBuilder;
use Morozov\PgCompat\DB\Dialect\PgIdentifier;
use Morozov\PgCompat\DB\Dialect\TypeMapper;

/**
 * Postgres-native replacement for MySQL\Definition\Columns\StringBinary (handles char/
 * varchar/binary/varbinary). char/varchar keep their length; binary/varbinary map to
 * Postgres' unbounded bytea (no length parameter - Postgres has no fixed/variable-length
 * binary type family the way MySQL does).
 */
class StringBinary implements DbDefinitionProcessorInterface
{
    private const BINARY_TYPES = ['binary', 'varbinary'];

    public function __construct(
        private readonly MysqlStringBinary $mysqlProcessor,
        private readonly Nullable $nullable,
        private readonly ResourceConnection $resourceConnection,
        private readonly ColumnDefinitionBuilder $columnBuilder,
        private readonly TypeMapper $types
    ) {
    }

    /**
     * @param \Magento\Framework\Setup\Declaration\Schema\Dto\Columns\StringBinary $column
     */
    public function toDefinition(ElementInterface $column)
    {
        $bareType = $this->types->declarativeTypeToPostgres($column->getType());
        $isBinary = in_array($column->getType(), self::BINARY_TYPES, true);
        $pgType = $isBinary ? $bareType : sprintf('%s(%d)', $bareType, $column->getLength());

        $nullableClause = $this->nullable->toDefinition($column);
        $sql = sprintf(
            '%s %s %s',
            $this->resourceConnection->getConnection()->quoteIdentifier($column->getName()),
            $pgType,
            $nullableClause
        );

        if ($column->getDefault() !== null) {
            $default = $isBinary
                ? ' DEFAULT ' . PgIdentifier::quoteValue((string) $column->getDefault()) . '::bytea'
                : $this->columnBuilder->explicitDefault($column->getDefault());
            return $sql . $default;
        }
        if ($nullableClause === 'NOT NULL') {
            return $sql . $this->columnBuilder->implicitNotNullDefault($isBinary ? 'bytea' : $pgType);
        }
        return $sql;
    }

    public function fromDefinition(array $data)
    {
        return $this->mysqlProcessor->fromDefinition($data);
    }
}
