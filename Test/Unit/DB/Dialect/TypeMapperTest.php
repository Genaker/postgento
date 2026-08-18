<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB\Dialect;

use Magento\Framework\DB\Ddl\Table;
use Morozov\PgCompat\DB\Dialect\TypeMapper;
use PHPUnit\Framework\TestCase;

class TypeMapperTest extends TestCase
{
    private TypeMapper $types;

    protected function setUp(): void
    {
        $this->types = new TypeMapper();
    }

    /**
     * @dataProvider forwardTypeProvider
     */
    public function testToPostgres(string $ddlType, array $options, string $expected): void
    {
        $this->assertSame($expected, $this->types->toPostgres($ddlType, $options));
    }

    public static function forwardTypeProvider(): array
    {
        return [
            'boolean' => [Table::TYPE_BOOLEAN, [], 'smallint'],
            'smallint' => [Table::TYPE_SMALLINT, [], 'smallint'],
            'smallint unsigned stays smallint' => [Table::TYPE_SMALLINT, ['UNSIGNED' => true], 'smallint'],
            'integer' => [Table::TYPE_INTEGER, [], 'integer'],
            'integer unsigned widens to bigint' => [Table::TYPE_INTEGER, ['UNSIGNED' => true], 'bigint'],
            'bigint' => [Table::TYPE_BIGINT, [], 'bigint'],
            'bigint unsigned stays bigint' => [Table::TYPE_BIGINT, ['UNSIGNED' => true], 'bigint'],
            'float' => [Table::TYPE_FLOAT, [], 'real'],
            'decimal default precision' => [Table::TYPE_DECIMAL, [], 'numeric(10,0)'],
            'decimal explicit precision/scale' => [Table::TYPE_DECIMAL, ['PRECISION' => 12, 'SCALE' => 4], 'numeric(12,4)'],
            'decimal length string' => [Table::TYPE_DECIMAL, ['LENGTH' => '12,4'], 'numeric(12,4)'],
            'numeric' => [Table::TYPE_NUMERIC, ['PRECISION' => 8, 'SCALE' => 2], 'numeric(8,2)'],
            'date' => [Table::TYPE_DATE, [], 'date'],
            'timestamp' => [Table::TYPE_TIMESTAMP, [], 'timestamp'],
            'datetime' => [Table::TYPE_DATETIME, [], 'timestamp'],
            'text short length -> varchar' => [Table::TYPE_TEXT, ['LENGTH' => 64], 'varchar(64)'],
            'text at boundary 255 -> varchar' => [Table::TYPE_TEXT, ['LENGTH' => 255], 'varchar(255)'],
            'text over 255 -> text' => [Table::TYPE_TEXT, ['LENGTH' => 256], 'text'],
            'text no length uses default size -> text' => [Table::TYPE_TEXT, [], 'text'],
            'blob' => [Table::TYPE_BLOB, [], 'bytea'],
            'varbinary' => [Table::TYPE_VARBINARY, [], 'bytea'],
        ];
    }

    public function testToPostgresRejectsUnsupportedType(): void
    {
        $this->expectException(\Zend_Db_Exception::class);
        $this->types->toPostgres('not_a_real_type', []);
    }

    /**
     * @dataProvider reverseTypeProvider
     */
    public function testToMagentoType(string $pgType, string $completeType, array $expected): void
    {
        $this->assertSame($expected, $this->types->toMagentoType($pgType, $completeType));
    }

    public static function reverseTypeProvider(): array
    {
        return [
            'int2' => ['int2', 'smallint', ['smallint', 'smallint(6)']],
            'int8' => ['int8', 'bigint', ['bigint', 'bigint(20)']],
            'int4' => ['int4', 'integer', ['int', 'int(11)']],
            'numeric with precision' => ['numeric', 'numeric(10,4)', ['decimal', 'decimal(10,4)']],
            'numeric without precision' => ['numeric', 'numeric', ['decimal', 'decimal(10,0)']],
            'real' => ['real', 'real', ['float', 'float']],
            'double precision' => ['float8', 'double precision', ['double', 'double']],
            'varchar with length' => ['varchar', 'character varying(255)', ['varchar', 'varchar(255)']],
            'varchar without length' => ['varchar', 'character varying', ['text', 'text']],
            'citext behaves like varchar' => ['citext', 'citext', ['text', 'text']],
            'text' => ['text', 'text', ['text', 'text']],
            'timestamp' => ['timestamp', 'timestamp without time zone', ['timestamp', 'timestamp']],
            'timestamptz' => ['timestamptz', 'timestamp with time zone', ['timestamp', 'timestamp']],
            'date' => ['date', 'date', ['date', 'date']],
            'bytea' => ['bytea', 'bytea', ['blob', 'blob']],
            'jsonb' => ['jsonb', 'jsonb', ['json', 'json']],
            'bool' => ['bool', 'boolean', ['smallint', 'smallint(6)']],
            'unknown falls back to text' => ['some_exotic_type', 'some_exotic_type', ['text', 'text']],
        ];
    }

    /**
     * @dataProvider declarativeTypeProvider
     */
    public function testDeclarativeTypeToPostgres(string $type, bool $unsigned, string $expected): void
    {
        $this->assertSame($expected, $this->types->declarativeTypeToPostgres($type, $unsigned));
    }

    public static function declarativeTypeProvider(): array
    {
        return [
            ['boolean', false, 'smallint'],
            ['smallint', false, 'smallint'],
            ['tinyint', false, 'smallint'],
            ['int', false, 'integer'],
            ['int', true, 'bigint'],
            ['bigint', true, 'bigint'],
            ['decimal', false, 'numeric'],
            ['float', false, 'real'],
            ['double', false, 'double precision'],
            ['text', false, 'text'],
            ['mediumtext', false, 'text'],
            ['longtext', false, 'text'],
            ['blob', false, 'bytea'],
            ['mediumblob', false, 'bytea'],
            ['longblob', false, 'bytea'],
            ['datetime', false, 'timestamp'],
            ['timestamp', false, 'timestamp'],
            ['date', false, 'date'],
            ['char', false, 'char'],
            ['varchar', false, 'varchar'],
            ['binary', false, 'bytea'],
            ['varbinary', false, 'bytea'],
            ['json', false, 'jsonb'],
        ];
    }

    public function testDeclarativeTypeToPostgresRejectsUnsupportedType(): void
    {
        $this->expectException(\Zend_Db_Exception::class);
        $this->types->declarativeTypeToPostgres('enum');
    }

    public function testKeywordReplacementsAreNeverEmpty(): void
    {
        $this->assertNotEmpty($this->types->keywordReplacements());
        foreach ($this->types->keywordReplacements() as $pair) {
            $this->assertCount(2, $pair);
            $this->assertIsString($pair[0]);
            $this->assertIsString($pair[1]);
        }
    }

    /**
     * @dataProvider keywordReplacementSamples
     */
    public function testKeywordReplacementsTranslateMysqlTypeText(string $mysqlFragment, string $expected): void
    {
        $sql = $mysqlFragment;
        foreach ($this->types->keywordReplacements() as [$pattern, $replacement]) {
            $sql = preg_replace($pattern, $replacement, $sql);
        }
        $this->assertSame($expected, $sql);
    }

    public static function keywordReplacementSamples(): array
    {
        return [
            'bigint unsigned' => ['BIGINT UNSIGNED', 'bigint'],
            'int unsigned widens' => ['INT UNSIGNED', 'bigint'],
            'smallint unsigned strips flag only' => ['SMALLINT UNSIGNED', 'smallint'],
            'tinyint(1) is boolean-ish smallint' => ['TINYINT(1)', 'smallint'],
            'tinyint(3)' => ['TINYINT(3)', 'smallint'],
            'mediumint' => ['MEDIUMINT', 'integer'],
            'int with length' => ['INT(11)', 'integer'],
            'double' => ['DOUBLE', 'double precision'],
            'float with precision' => ['FLOAT(10,2)', 'real'],
            'decimal' => ['DECIMAL(10,2)', 'numeric(10,2)'],
            'datetime' => ['DATETIME', 'timestamp'],
            'longtext' => ['LONGTEXT', 'text'],
            'varbinary with length' => ['VARBINARY(16)', 'bytea'],
            'json' => ['JSON', 'jsonb'],
            'boolean' => ['BOOLEAN', 'smallint'],
        ];
    }
}
