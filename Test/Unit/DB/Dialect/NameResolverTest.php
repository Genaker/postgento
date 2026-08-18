<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB\Dialect;

use Morozov\PgCompat\DB\Dialect\NameResolver;
use PHPUnit\Framework\TestCase;

class NameResolverTest extends TestCase
{
    private NameResolver $names;

    protected function setUp(): void
    {
        $this->names = new NameResolver();
    }

    public function testShortNamePassesThroughUnchanged(): void
    {
        $this->assertSame('short_name', $this->names->fit('short_name'));
    }

    public function testLongNameIsTruncatedAndHashed(): void
    {
        $long = str_repeat('a', 100);
        $fitted = $this->names->fit($long);

        $this->assertLessThanOrEqual(63, strlen($fitted));
        $this->assertStringStartsWith(str_repeat('a', 55), $fitted);
        $this->assertMatchesRegularExpression('/_[0-9a-f]{7}$/', $fitted);
    }

    public function testFitIsDeterministic(): void
    {
        $long = str_repeat('b', 80);
        $this->assertSame($this->names->fit($long), $this->names->fit($long));
    }

    public function testFitProducesDifferentHashesForDifferentInputs(): void
    {
        $a = str_repeat('c', 80) . '1';
        $b = str_repeat('c', 80) . '2';
        $this->assertNotSame($this->names->fit($a), $this->names->fit($b));
    }

    public function testConstraintNamePrefixesWithTable(): void
    {
        $this->assertSame(
            'catalog_product_entity_UNQ_SKU',
            $this->names->constraintName('catalog_product_entity', 'UNQ_SKU')
        );
    }

    public function testConstraintNameIsFittedWhenTooLong(): void
    {
        $result = $this->names->constraintName(str_repeat('t', 40), str_repeat('c', 40));
        $this->assertLessThanOrEqual(63, strlen($result));
    }

    public function testRelationNamePrefixesWithTableWhenGiven(): void
    {
        $this->assertSame('my_table_IDX_NAME', $this->names->relationName('IDX_NAME', 'my_table'));
    }

    public function testRelationNameWithoutTableIsUnprefixed(): void
    {
        $this->assertSame('IDX_NAME', $this->names->relationName('IDX_NAME', null));
    }
}
