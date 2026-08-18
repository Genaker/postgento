<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Compat\Catalog;

/**
 * Wraps joined-table columns that Magento selects next to a GROUP BY on e.entity_id.
 * Postgres rejects those; MAX() is a no-op when the join is 1:1 per product.
 *
 * Aliases match CatalogGraphQl stock/status/visibility and CatalogRule price joins
 * used by ConfigurableProductGraphQl\Model\Variant\Collection::fetch().
 */
final class GroupedColumnAggregator
{
    private const ALIASES = ['is_salable', 'status', 'visibility', 'catalog_rule_price'];

    /**
     * @param array<int, array> $columns Zend_Db_Select::COLUMNS entries
     * @return array<int, array>
     */
    public function wrap(array $columns): array
    {
        foreach ($columns as $i => $column) {
            $columns[$i] = $this->wrapColumn($column);
        }
        return $columns;
    }

    private function wrapColumn(array $column): array
    {
        $corr = $column[0] ?? '';
        $col = $column[1] ?? '';
        $alias = $column[2] ?? (is_string($col) ? $col : '');
        $matchesAlias = in_array((string) $alias, self::ALIASES, true);
        $matchesRuleJoin = $corr === 'catalog_rule' && ($col === 'rule_price' || $alias === 'catalog_rule_price');
        if (!$matchesAlias && !$matchesRuleJoin) {
            return $column;
        }
        $inner = $col instanceof \Zend_Db_Expr
            ? (string) $col
            : (($corr !== '' && $corr !== null) ? $corr . '.' . $col : (string) $col);
        if (preg_match('/^\s*(MIN|MAX|SUM|COUNT|AVG|string_agg)\s*\(/i', $inner)) {
            return $column;
        }
        return ['', new \Zend_Db_Expr('MAX(' . $inner . ')'), $alias];
    }
}
