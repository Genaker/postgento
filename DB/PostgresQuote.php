<?php

namespace Morozov\PgCompat\DB;

class PostgresQuote extends \Magento\Framework\DB\Platform\Quote
{
    protected function getQuoteIdentifierSymbol()
    {
        try {
            $engine = \Magento\Framework\App\ObjectManager::getInstance()->get(EngineResolver::class);
            return $engine->isPostgres() ? '"' : '`';
        } catch (\Throwable $e) {
            return '"';
        }
    }
}
