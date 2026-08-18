<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Compat\CatalogInventory;

use Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\QueryProcessorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Zend_Db_Expr;

/**
 * Fixes: app/code/Magento/CatalogInventory/Model/ResourceModel/Indexer/Stock/
 * DefaultStock.php, _getStockStatusSelect() (:252). Line 255,
 * `$qtyExpr = $connection->getCheckSql('cisi.qty > 0', 'cisi.qty', 0)`, renders as
 * `CASE WHEN cisi.qty > 0 THEN cisi.qty ELSE 0 END` and is selected as the "qty" column
 * (:279, `->columns(['qty' => $qtyExpr])`) under `->group(['e.entity_id',
 * 'cis.website_id', 'cis.stock_id'])` (:285) - cisi.qty is a non-grouped column
 * reference in both the CASE condition and its THEN branch, tolerated by MySQL's
 * non-ONLY_FULL_GROUP_BY mode and rejected by Postgres. This class finds the "qty"
 * column by its output alias (processQuery() below) and replaces it with the
 * MAX(cisi.qty)-wrapped equivalent, independent of exactly how DefaultStock built it.
 *
 * If a future Magento version renames the "qty" output alias in
 * _getStockStatusSelect(), this processor's `$column[2] === 'qty'` match stops firing
 * and the fix silently drops out - re-derive the alias name from the new version.
 *
 * Registered into QueryProcessorComposite - the official Magento extension point for
 * modifying this exact select (di.xml array argument), not a text patch on the
 * assembled SQL.
 */
class GroupBySafeStockStatusQueryProcessor implements QueryProcessorInterface
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function processQuery(Select $select, $entityIds = null, $usePrimaryTable = false)
    {
        $connection = $this->resourceConnection->getConnection();
        $columns = $select->getPart(Select::COLUMNS);
        foreach ($columns as &$column) {
            if (($column[2] ?? null) === 'qty') {
                $column[1] = new Zend_Db_Expr(
                    $connection->getCheckSql('MAX(cisi.qty) > 0', 'MAX(cisi.qty)', 0)
                );
            }
        }
        unset($column);
        $select->setPart(Select::COLUMNS, $columns);
        return $select;
    }
}
