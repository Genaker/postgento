<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB\Dialect;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Morozov\PgCompat\DB\Dialect\UpsertBuilder;
use PHPUnit\Framework\TestCase;

class UpsertBuilderTest extends TestCase
{
    private UpsertBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new UpsertBuilder();
    }

    public function testOnConflictClauseWithNoTargetIsEmpty(): void
    {
        $this->assertSame('', $this->builder->onConflictClause([], ['col']));
    }

    public function testOnConflictClauseWithNoUpdateColumnsDoesNothing(): void
    {
        $this->assertSame(
            ' ON CONFLICT ("sku") DO NOTHING',
            $this->builder->onConflictClause(['sku'], [])
        );
    }

    public function testOnConflictClauseUpdatesGivenColumnsFromExcluded(): void
    {
        $this->assertSame(
            ' ON CONFLICT ("sku") DO UPDATE SET "name" = EXCLUDED."name", "price" = EXCLUDED."price"',
            $this->builder->onConflictClause(['sku'], ['name', 'price'])
        );
    }

    public function testOnConflictClauseWithCompositeTarget(): void
    {
        $result = $this->builder->onConflictClause(['store_id', 'product_id'], ['qty']);
        $this->assertSame(
            ' ON CONFLICT ("store_id", "product_id") DO UPDATE SET "qty" = EXCLUDED."qty"',
            $result
        );
    }

    public function testConflictTargetPrefersUniqueIndexOverPrimary(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getIndexList')->willReturn([
            'PRIMARY' => ['INDEX_TYPE' => 'primary', 'COLUMNS_LIST' => ['entity_id']],
            'UNQ_SKU' => ['INDEX_TYPE' => 'unique', 'COLUMNS_LIST' => ['sku']],
        ]);

        $result = $this->builder->conflictTarget($adapter, 'catalog_product_entity', ['sku', 'name']);
        $this->assertSame(['sku'], $result);
    }

    public function testConflictTargetFallsBackToPrimaryWhenNoUniqueMatches(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getIndexList')->willReturn([
            'PRIMARY' => ['INDEX_TYPE' => 'primary', 'COLUMNS_LIST' => ['entity_id']],
        ]);

        $result = $this->builder->conflictTarget($adapter, 't', ['entity_id', 'value']);
        $this->assertSame(['entity_id'], $result);
    }

    public function testConflictTargetIsEmptyWhenNoIndexColumnsAreAllPresent(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getIndexList')->willReturn([
            'UNQ_A' => ['INDEX_TYPE' => 'unique', 'COLUMNS_LIST' => ['a', 'b']],
        ]);

        // only "a" is present in the insert, not "b" - index can't be a conflict target
        $result = $this->builder->conflictTarget($adapter, 't', ['a']);
        $this->assertSame([], $result);
    }

    public function testConflictTargetIsCaseInsensitive(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getIndexList')->willReturn([
            'UNQ_SKU' => ['INDEX_TYPE' => 'unique', 'COLUMNS_LIST' => ['Sku']],
        ]);

        $result = $this->builder->conflictTarget($adapter, 't', ['SKU']);
        $this->assertSame(['Sku'], $result);
    }

    public function testConflictTargetStripsQuotesFromTableName(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())
            ->method('getIndexList')
            ->with('catalog_product_entity')
            ->willReturn([]);

        $this->builder->conflictTarget($adapter, '"catalog_product_entity"', ['sku']);
    }
}
