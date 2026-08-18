<?php

namespace Morozov\PgCompat\Compat\AsynchronousOperations\Model\BulkStatus;

use Magento\AsynchronousOperations\Api\Data\BulkSummaryInterface;

/**
 * Fixes: app/code/Magento/AsynchronousOperations/Model/BulkStatus/CalculatedStatusSql.php,
 * get() (:19-31). Lines 22-29 build a raw
 * `IF(cond, then_value, else_expr)` expression - MySQL-only syntax with no Postgres
 * equivalent (Postgres' IF() is PL/pgSQL-only, not usable in a plain SELECT). Rewritten
 * here as the equivalent `CASE WHEN cond THEN then_value ELSE else_expr END`, which both
 * databases support - a full class preference (di.xml) rather than a text-patch
 * transformer, since the whole method body is one self-contained `public` method with
 * no larger surface to risk diverging from.
 *
 * If a future Magento version changes this expression's structure (not just the IF/CASE
 * keyword), re-derive this override from the new version.
 */
class CalculatedStatusSql extends \Magento\AsynchronousOperations\Model\BulkStatus\CalculatedStatusSql
{
    public function get($operationTableName)
    {
        return new \Zend_Db_Expr(
            '(CASE WHEN
                (SELECT count(*)
                    FROM ' . $operationTableName . '
                    WHERE bulk_uuid = main_table.uuid
                ) = 0
                THEN ' . BulkSummaryInterface::NOT_STARTED . '
                ELSE (SELECT MAX(status) FROM ' . $operationTableName . ' WHERE bulk_uuid = main_table.uuid)
                END
            )'
        );
    }
}
