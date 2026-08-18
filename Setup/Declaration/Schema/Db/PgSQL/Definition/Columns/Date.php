<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Setup\Declaration\Schema\Db\PgSQL\Definition\Columns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbDefinitionProcessorInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Date as MysqlDate;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\Definition\Columns\Nullable;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;

/**
 * Postgres-native replacement for MySQL\Definition\Columns\Date - "date" is spelled the
 * same in both dialects, so this only needs to skip the MySQL fragment-rendering step,
 * not translate anything.
 */
class Date implements DbDefinitionProcessorInterface
{
    public function __construct(
        private readonly MysqlDate $mysqlProcessor,
        private readonly Nullable $nullable,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function toDefinition(ElementInterface $column)
    {
        return sprintf(
            '%s date %s',
            $this->resourceConnection->getConnection()->quoteIdentifier($column->getName()),
            $this->nullable->toDefinition($column)
        );
    }

    public function fromDefinition(array $data)
    {
        return $this->mysqlProcessor->fromDefinition($data);
    }
}
