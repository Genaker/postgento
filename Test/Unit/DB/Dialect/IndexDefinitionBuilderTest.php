<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB\Dialect;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Morozov\PgCompat\DB\Dialect\IndexDefinitionBuilder;
use Morozov\PgCompat\DB\Dialect\NameResolver;
use PHPUnit\Framework\TestCase;

class IndexDefinitionBuilderTest extends TestCase
{
    private IndexDefinitionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new IndexDefinitionBuilder(new NameResolver());
    }

    public function testPrimaryIndexIsInline(): void
    {
        $result = $this->builder->build('my_table', [
            'TYPE' => AdapterInterface::INDEX_TYPE_PRIMARY,
            'COLUMNS' => [['NAME' => 'entity_id']],
        ]);

        $this->assertSame('PRIMARY KEY ("entity_id")', $result['inline']);
        $this->assertNull($result['deferred']);
    }

    public function testUniqueIndexIsInline(): void
    {
        $result = $this->builder->build('my_table', [
            'TYPE' => AdapterInterface::INDEX_TYPE_UNIQUE,
            'COLUMNS' => [['NAME' => 'sku']],
        ]);

        $this->assertSame('UNIQUE ("sku")', $result['inline']);
        $this->assertNull($result['deferred']);
    }

    public function testCompositeUniqueIndexQuotesAllColumns(): void
    {
        $result = $this->builder->build('my_table', [
            'TYPE' => AdapterInterface::INDEX_TYPE_UNIQUE,
            'COLUMNS' => [['NAME' => 'a'], ['NAME' => 'b']],
        ]);

        $this->assertSame('UNIQUE ("a", "b")', $result['inline']);
    }

    public function testPlainIndexIsDeferredAsCreateIndexStatement(): void
    {
        $result = $this->builder->build('catalog_product_entity', [
            'INDEX_NAME' => 'IDX_SKU',
            'COLUMNS' => [['NAME' => 'sku']],
        ]);

        $this->assertNull($result['inline']);
        $this->assertSame(
            'CREATE INDEX IF NOT EXISTS "catalog_product_entity_IDX_SKU" ON "catalog_product_entity" ("sku")',
            $result['deferred']
        );
    }

    public function testDefaultTypeIsTreatedAsPlainIndex(): void
    {
        $result = $this->builder->build('t', [
            'INDEX_NAME' => 'IDX_X',
            'COLUMNS' => [['NAME' => 'x']],
        ]);

        $this->assertNotNull($result['deferred']);
        $this->assertNull($result['inline']);
    }

    public function testFulltextIndexIsDroppedEntirely(): void
    {
        $result = $this->builder->build('my_table', [
            'TYPE' => AdapterInterface::INDEX_TYPE_FULLTEXT,
            'COLUMNS' => [['NAME' => 'description']],
        ]);

        $this->assertNull($result['inline']);
        $this->assertNull($result['deferred']);
    }

    public function testIndexWithNoColumnsProducesNothing(): void
    {
        $result = $this->builder->build('my_table', [
            'TYPE' => AdapterInterface::INDEX_TYPE_INDEX,
            'COLUMNS' => [],
        ]);

        $this->assertNull($result['inline']);
        $this->assertNull($result['deferred']);
    }

    public function testDeferredIndexNameFallsBackToJoinedColumnsWhenUnnamed(): void
    {
        $result = $this->builder->build('t', [
            'COLUMNS' => [['NAME' => 'a'], ['NAME' => 'b']],
        ]);

        $this->assertSame('CREATE INDEX IF NOT EXISTS "t_a_b" ON "t" ("a", "b")', $result['deferred']);
    }

    public function testLongIndexNameIsFitted(): void
    {
        $result = $this->builder->build(str_repeat('t', 40), [
            'INDEX_NAME' => str_repeat('i', 40),
            'COLUMNS' => [['NAME' => 'x']],
        ]);

        // "CREATE INDEX IF NOT EXISTS " + quoted name + " ON " ...
        preg_match('/CREATE INDEX IF NOT EXISTS "([^"]+)"/', $result['deferred'], $m);
        $this->assertLessThanOrEqual(63, strlen($m[1]));
    }
}
