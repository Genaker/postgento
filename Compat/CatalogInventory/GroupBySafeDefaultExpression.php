<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Compat\CatalogInventory;

use Magento\CatalogInventory\Model\Configuration;
use Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\StatusExpression\ExpressionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\ScopeInterface;
use Zend_Db_Expr;

/**
 * Fixes: app/code/Magento/CatalogInventory/Model/ResourceModel/Indexer/Stock/
 * StatusExpression/DefaultExpression.php, getExpression() (:38-60). Lines 45-51/53-57
 * build a CASE WHEN condition (`cisi.use_config_manage_stock = 0 AND cisi.manage_stock
 * = 0/1`) that only wraps the is_in_stock *branch* in MAX() when $isAggregate is true
 * (:45) - it leaves use_config_manage_stock/manage_stock bare in the WHEN clause
 * itself. Both are non-grouped columns in the query that calls this with
 * $isAggregate=true: app/code/Magento/CatalogInventory/Model/ResourceModel/Indexer/
 * Stock/DefaultStock.php, _getStockStatusSelect() (:252), `->group(['e.entity_id',
 * 'cis.website_id', 'cis.stock_id'])` (:285) then `$select->columns(['status' =>
 * $this->getStatusExpression($connection, true)])` (:288) - tolerated by MySQL's
 * non-ONLY_FULL_GROUP_BY mode, rejected by Postgres. This class wraps all three
 * columns (is_in_stock/use_config_manage_stock/manage_stock) in MAX() when
 * $isAggregate, not just the first.
 *
 * If a future Magento version changes DefaultExpression::getExpression()'s CASE
 * condition, re-derive this override from the new version rather than assuming the
 * MAX()-wrapping gap still exists in the same place.
 *
 * Registered in place of the 'default' entry of GetStatusExpression's
 * $statusExpressions DI array argument (di.xml), not a class preference - every other
 * consumer of ExpressionInterface is unaffected.
 */
class GroupBySafeDefaultExpression implements ExpressionInterface
{
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function getExpression(AdapterInterface $connection, bool $isAggregate): Zend_Db_Expr
    {
        $isManageStock = $this->scopeConfig->isSetFlag(
            Configuration::XML_PATH_MANAGE_STOCK,
            ScopeInterface::SCOPE_STORE
        );

        $isInStockExpression = $isAggregate ? 'MAX(cisi.is_in_stock)' : 'cisi.is_in_stock';
        $useConfigManageStock = $isAggregate ? 'MAX(cisi.use_config_manage_stock)' : 'cisi.use_config_manage_stock';
        $manageStock = $isAggregate ? 'MAX(cisi.manage_stock)' : 'cisi.manage_stock';

        if ($isManageStock) {
            return $connection->getCheckSql(
                "$useConfigManageStock = 0 AND $manageStock = 0",
                1,
                $isInStockExpression
            );
        }
        return $connection->getCheckSql(
            "$useConfigManageStock = 0 AND $manageStock = 1",
            $isInStockExpression,
            1
        );
    }
}
