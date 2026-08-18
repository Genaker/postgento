<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB\Connection;

use Morozov\PgCompat\DB\Connection\EmptyIdCondition;
use PHPUnit\Framework\TestCase;

class EmptyIdConditionTest extends TestCase
{
    private EmptyIdCondition $emptyId;

    protected function setUp(): void
    {
        $this->emptyId = new EmptyIdCondition();
    }

    /**
     * @dataProvider idFieldProvider
     */
    public function testIsIdField(string $fieldName, bool $expected): void
    {
        $this->assertSame($expected, $this->emptyId->isIdField($fieldName));
    }

    public static function idFieldProvider(): array
    {
        return [
            'bare' => ['entity_id', true],
            'quoted' => ['"entity_id"', true],
            'qualified' => ['e.entity_id', true],
            'quoted qualified' => ['"e"."entity_id"', true],
            'attribute_id' => ['main_table.attribute_id', true],
            'quoteInto eq' => ['"quote"."entity_id"=?', true],
            'quoteInto in' => ['entity_id IN (?)', true],
            'sku is not an id' => ['sku', false],
            'sku quoteInto' => ['sku = ?', false],
            'status' => ['status', false],
        ];
    }

    public function testCoerceEmptyEqOnIdToZero(): void
    {
        $this->assertSame(0, $this->emptyId->coerceEqOrGt('entity_id', ''));
        $this->assertSame(0, $this->emptyId->coerceEqOrGt('"e"."product_id"', ''));
        $this->assertSame(0, $this->emptyId->coerceEqOrGt('"quote"."entity_id"=?', ''));
    }

    public function testCoerceLeavesNonEmptyAndNonIdAlone(): void
    {
        $this->assertSame('5', $this->emptyId->coerceEqOrGt('entity_id', '5'));
        $this->assertSame('', $this->emptyId->coerceEqOrGt('sku', ''));
    }

    public function testEmptyInListOnId(): void
    {
        $this->assertTrue($this->emptyId->isEmptyInList('entity_id', ''));
        $this->assertTrue($this->emptyId->isEmptyInList('entity_id', ['']));
        $this->assertTrue($this->emptyId->isEmptyInList('main_table.product_id', [false]));
        $this->assertTrue($this->emptyId->isEmptyInList('entity_id', false));
        $this->assertFalse($this->emptyId->isEmptyInList('entity_id', ['1']));
        $this->assertFalse($this->emptyId->isEmptyInList('sku', ''));
    }
}
