<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Setup;

use Magento\Framework\Setup\Declaration\Schema\Db\DbSchemaReaderInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\MySQL\DbSchemaReader as MysqlDbSchemaReader;
use Morozov\PgCompat\DB\EngineResolver;

class DelegatingDbSchemaReader implements DbSchemaReaderInterface
{
    public function __construct(
        private readonly EngineResolver $engine,
        private readonly DbSchemaReader $postgres,
        private readonly MysqlDbSchemaReader $mysql
    ) {
    }

    private function inner(): DbSchemaReaderInterface
    {
        return $this->engine->isPostgres() ? $this->postgres : $this->mysql;
    }

    public function readIndexes($tableName, $resource)
    {
        return $this->inner()->readIndexes($tableName, $resource);
    }

    public function readConstraints($tableName, $resource)
    {
        return $this->inner()->readConstraints($tableName, $resource);
    }

    public function readColumns($tableName, $resource)
    {
        return $this->inner()->readColumns($tableName, $resource);
    }

    public function getTableOptions($tableName, $resource)
    {
        return $this->inner()->getTableOptions($tableName, $resource);
    }

    public function readReferences($tableName, $resource)
    {
        return $this->inner()->readReferences($tableName, $resource);
    }

    public function readTables($resource)
    {
        return $this->inner()->readTables($resource);
    }
}
