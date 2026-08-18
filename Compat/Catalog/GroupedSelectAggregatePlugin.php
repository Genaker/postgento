<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Compat\Catalog;

use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Product\Collection;
use Magento\Framework\DB\Select;

/**
 * CatalogRuleConfigurable::beforeLoad adds catalog_rule_price during load(), after
 * CollectionPostProcessor plugins have already run. This plugin is sortOrder 100 on
 * the same Child Collection so it wraps after that join exists.
 */
class GroupedSelectAggregatePlugin
{
    public function __construct(private readonly GroupedColumnAggregator $aggregator)
    {
    }

    public function beforeLoad(Collection $subject, $printQuery = false, $logQuery = false): array
    {
        $select = $subject->getSelect();
        if ($select->getPart(Select::GROUP)) {
            $select->setPart(Select::COLUMNS, $this->aggregator->wrap($select->getPart(Select::COLUMNS)));
        }
        return [$printQuery, $logQuery];
    }
}
