<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Setup;

use Magento\Framework\DB\Adapter\SqlVersionProvider;
use Magento\Framework\Setup\Declaration\Schema\Db\DefinitionAggregator as MagentoDefinitionAggregator;
use Morozov\PgCompat\DB\EngineResolver;

class DefinitionAggregator extends MagentoDefinitionAggregator
{
    public function __construct(
        SqlVersionProvider $sqlVersionProvider,
        EngineResolver $engine,
        array $mysqlProcessors,
        array $postgresProcessors
    ) {
        parent::__construct(
            $sqlVersionProvider,
            $engine->isPostgres() ? $postgresProcessors : $mysqlProcessors
        );
    }
}
