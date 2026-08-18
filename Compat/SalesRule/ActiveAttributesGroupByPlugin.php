<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Compat\SalesRule;

use Magento\SalesRule\Model\ResourceModel\Rule;

/**
 * Fixes: app/code/Magento/SalesRule/Model/ResourceModel/Rule.php,
 * getActiveAttributes() (:309). Lines 315-316,
 * `$subSelect->from($this->getTable('salesrule_product_attribute'))->group('attribute_id')`
 * - no column list passed to from(), so it defaults to `*`, rendering as
 * `SELECT * FROM salesrule_product_attribute GROUP BY attribute_id`. MySQL's
 * non-ONLY_FULL_GROUP_BY mode tolerates the unaggregated extra columns; Postgres does
 * not. Only attribute_id is actually used by the outer join (:318-323), so selecting
 * just that column (see aroundGetActiveAttributes() below) removes the problem entirely
 * instead of working around it.
 *
 * If a future Magento version changes what this method selects from
 * salesrule_product_attribute (e.g. starts using additional columns from the
 * subquery), this plugin's narrowed column list would need to grow to match - it
 * currently assumes attribute_id is the only column the outer join needs.
 *
 * An `around` plugin (rather than a DI-array override, unlike the CatalogInventory
 * fixes) because this query isn't behind a composite extension point - it's the whole
 * body of one small, public, self-contained method.
 */
class ActiveAttributesGroupByPlugin
{
    public function aroundGetActiveAttributes(Rule $subject, callable $proceed): array
    {
        $connection = $subject->getConnection();
        $subSelect = $connection->select();
        $subSelect->from($subject->getTable('salesrule_product_attribute'), ['attribute_id'])
            ->group('attribute_id');
        $select = $connection->select()->from(
            ['a' => $subSelect],
            new \Zend_Db_Expr('ea.attribute_code')
        )->joinInner(
            ['ea' => $subject->getTable('eav_attribute')],
            'ea.attribute_id = a.attribute_id',
            []
        );

        return $connection->fetchAll($select);
    }
}
