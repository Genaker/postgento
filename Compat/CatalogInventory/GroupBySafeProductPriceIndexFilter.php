<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Compat\CatalogInventory;

use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\IndexTableStructure;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\PriceModifierInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Item;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Query\BatchIteratorInterface;
use Magento\Framework\DB\Query\Generator;

/**
 * Fixes: app/code/Magento/CatalogInventory/Model/Indexer/ProductPriceIndexFilter.php,
 * modifyPrice() (:87). Line 98 selects
 * `'MAX(stock_item.is_in_stock) as max_is_in_stock'`; line 112,
 * `$select->having('max_is_in_stock = 0')`, references that output alias in the HAVING
 * clause instead of repeating the aggregate expression. MySQL allows a HAVING clause to
 * reference an output column alias; Postgres evaluates HAVING before the SELECT list is
 * projected, so the alias isn't in scope there and it has to be the full aggregate
 * expression again - see line 78 below (`having('MAX(stock_item.is_in_stock) = 0')`).
 *
 * If a future Magento version changes this method's column alias or HAVING clause,
 * re-derive this override from the new version rather than assuming the alias-in-HAVING
 * gap still exists in the same place.
 *
 * The parent's dependencies are private, so this can't extend-and-delegate a partial
 * override - registered as a full replacement of the 'inventoryProductPriceIndexFilter'
 * DI array entry (di.xml), not a class preference, so nothing else that might reference
 * the original class is affected.
 */
class GroupBySafeProductPriceIndexFilter implements PriceModifierInterface
{
    private StockConfigurationInterface $stockConfiguration;
    private Item $stockItem;
    private ResourceConnection $resourceConnection;
    private string $connectionName;
    private Generator $batchQueryGenerator;
    private int $batchSize;

    public function __construct(
        StockConfigurationInterface $stockConfiguration,
        Item $stockItem,
        ?ResourceConnection $resourceConnection = null,
        $connectionName = 'indexer',
        ?Generator $batchQueryGenerator = null,
        $batchSize = 100
    ) {
        $this->stockConfiguration = $stockConfiguration;
        $this->stockItem = $stockItem;
        $this->resourceConnection = $resourceConnection ?: ObjectManager::getInstance()->get(ResourceConnection::class);
        $this->connectionName = $connectionName;
        $this->batchQueryGenerator = $batchQueryGenerator ?: ObjectManager::getInstance()->get(Generator::class);
        $this->batchSize = $batchSize;
    }

    public function modifyPrice(IndexTableStructure $priceTable, array $entityIds = []): void
    {
        if ($this->stockConfiguration->isShowOutOfStock()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection($this->connectionName);
        $select = $connection->select();

        $select->from(
            ['stock_item' => $this->stockItem->getMainTable()],
            ['stock_item.product_id', 'MAX(stock_item.is_in_stock) as max_is_in_stock']
        );

        if ($this->stockConfiguration->getManageStock()) {
            $select->where('stock_item.use_config_manage_stock = 1 OR stock_item.manage_stock = 1');
        } else {
            $select->where('stock_item.use_config_manage_stock = 0 AND stock_item.manage_stock = 1');
        }

        if (!empty($entityIds)) {
            $select->where('stock_item.product_id IN (?)', $entityIds, \Zend_Db::INT_TYPE);
        }

        $select->group('stock_item.product_id');
        $select->having('MAX(stock_item.is_in_stock) = 0');

        $batchSelectIterator = $this->batchQueryGenerator->generate(
            'product_id',
            $select,
            $this->batchSize,
            BatchIteratorInterface::UNIQUE_FIELD_ITERATOR
        );

        foreach ($batchSelectIterator as $batchSelect) {
            $productIds = null;
            foreach ($connection->query($batchSelect)->fetchAll() as $row) {
                $productIds[] = (int) $row['product_id'];
            }
            if ($productIds !== null) {
                $where = [$priceTable->getEntityField() . ' IN (?)' => $productIds];
                $connection->delete($priceTable->getTableName(), $where);
            }
        }
    }
}
