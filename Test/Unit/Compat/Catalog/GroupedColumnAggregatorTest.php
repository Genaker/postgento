<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\Compat\Catalog;

use Morozov\PgCompat\Compat\Catalog\GroupedColumnAggregator;
use PHPUnit\Framework\TestCase;

class GroupedColumnAggregatorTest extends TestCase
{
    private GroupedColumnAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new GroupedColumnAggregator();
    }

    public function testWrapsIsSalableJoinColumn(): void
    {
        $columns = [
            ['e', 'entity_id', 'entity_id'],
            ['stock_status_index', 'stock_status', 'is_salable'],
        ];
        $result = $this->aggregator->wrap($columns);
        $this->assertSame(['e', 'entity_id', 'entity_id'], $result[0]);
        $this->assertSame('', $result[1][0]);
        $this->assertInstanceOf(\Zend_Db_Expr::class, $result[1][1]);
        $this->assertSame('MAX(stock_status_index.stock_status)', (string) $result[1][1]);
        $this->assertSame('is_salable', $result[1][2]);
    }

    public function testWrapsStatusCaseExpression(): void
    {
        $expr = new \Zend_Db_Expr(
            'CASE WHEN at_status.value_id > 0 THEN at_status.value ELSE at_status_default.value END'
        );
        $result = $this->aggregator->wrap([['', $expr, 'status']]);
        $this->assertStringStartsWith('MAX(', (string) $result[0][1]);
        $this->assertStringContainsString('at_status.value', (string) $result[0][1]);
        $this->assertSame('status', $result[0][2]);
    }

    public function testDoesNotDoubleWrapAlreadyAggregated(): void
    {
        $expr = new \Zend_Db_Expr('MAX(stock_status_index.stock_status)');
        $result = $this->aggregator->wrap([['', $expr, 'is_salable']]);
        $this->assertSame($expr, $result[0][1]);
    }

    public function testWrapsCatalogRulePriceJoinColumn(): void
    {
        $columns = [['catalog_rule', 'rule_price', 'catalog_rule_price']];
        $result = $this->aggregator->wrap($columns);
        $this->assertSame('MAX(catalog_rule.rule_price)', (string) $result[0][1]);
        $this->assertSame('catalog_rule_price', $result[0][2]);
    }

    public function testLeavesUnrelatedColumnsAlone(): void
    {
        $columns = [['e', 'sku', 'sku']];
        $this->assertSame($columns, $this->aggregator->wrap($columns));
    }
}
