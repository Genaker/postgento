<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB\Dialect;

use Magento\Framework\DB\Ddl\Table;
use Morozov\PgCompat\DB\Dialect\TypeMapper;
use PHPUnit\Framework\TestCase;

/**
 * TypeMapper has three type-mapping entry points because it serves three genuinely
 * different input vocabularies - Table::TYPE_* constants (imperative DDL),
 * declarative-schema type-key strings, and raw MySQL keyword text (the regex fallback)
 * - so they can't be merged into one method. But the underlying *decisions* (unsigned
 * int widens to bigint, tinyint(1)/boolean collapses to smallint, json becomes jsonb,
 * blob becomes bytea, ...) have to agree across whichever of the three apply to a given
 * logical type, or the same column could come out different types depending on which
 * Magento API built it. This test is the guardrail: it fails the moment one of the
 * three is edited without the others.
 */
class TypeMapperConsistencyTest extends TestCase
{
    private TypeMapper $types;

    protected function setUp(): void
    {
        $this->types = new TypeMapper();
    }

    /**
     * @dataProvider ddlVsDeclarativeProvider
     */
    public function testDdlAndDeclarativeAgreeOnBareType(
        string $ddlType,
        string $declarativeType,
        array $ddlOptions,
        bool $unsigned
    ): void {
        $viaDdl = $this->types->toPostgres($ddlType, $ddlOptions);
        $viaDeclarative = $this->types->declarativeTypeToPostgres($declarativeType, $unsigned);

        $this->assertSame(
            $viaDdl,
            $viaDeclarative,
            "toPostgres('$ddlType') and declarativeTypeToPostgres('$declarativeType') disagree"
        );
    }

    /**
     * Every case here is a logical type reachable through both the imperative
     * (Table::TYPE_*) and declarative-schema (type-key string) paths.
     */
    public static function ddlVsDeclarativeProvider(): array
    {
        return [
            'boolean' => [Table::TYPE_BOOLEAN, 'boolean', [], false],
            'smallint' => [Table::TYPE_SMALLINT, 'smallint', [], false],
            'integer' => [Table::TYPE_INTEGER, 'int', [], false],
            'integer unsigned widens to bigint' => [Table::TYPE_INTEGER, 'int', ['UNSIGNED' => true], true],
            'bigint' => [Table::TYPE_BIGINT, 'bigint', [], false],
            'float' => [Table::TYPE_FLOAT, 'float', [], false],
            'date' => [Table::TYPE_DATE, 'date', [], false],
            'timestamp' => [Table::TYPE_TIMESTAMP, 'timestamp', [], false],
            'datetime' => [Table::TYPE_DATETIME, 'datetime', [], false],
            'blob' => [Table::TYPE_BLOB, 'blob', [], false],
            'varbinary' => [Table::TYPE_VARBINARY, 'varbinary', [], false],
        ];
    }

    /**
     * @dataProvider declarativeVsKeywordProvider
     */
    public function testDeclarativeAndKeywordRegexAgree(
        string $declarativeType,
        string $mysqlKeyword,
        bool $unsigned = false
    ): void {
        $viaDeclarative = $this->types->declarativeTypeToPostgres($declarativeType, $unsigned);

        $viaKeyword = $mysqlKeyword;
        foreach ($this->types->keywordReplacements() as [$pattern, $replacement]) {
            $viaKeyword = preg_replace($pattern, $replacement, $viaKeyword);
        }

        $this->assertSame(
            $viaDeclarative,
            trim($viaKeyword),
            "declarativeTypeToPostgres('$declarativeType') and the keyword-regex translation of "
            . "'$mysqlKeyword' disagree"
        );
    }

    /**
     * Every case here is a logical type reachable through both the declarative-schema
     * path and the raw-MySQL-text regex fallback (MysqlToPostgres::translateFragment()).
     */
    public static function declarativeVsKeywordProvider(): array
    {
        return [
            'smallint' => ['smallint', 'SMALLINT'],
            'tinyint' => ['tinyint', 'TINYINT'],
            'bigint' => ['bigint', 'BIGINT'],
            'decimal' => ['decimal', 'DECIMAL'],
            'float' => ['float', 'FLOAT'],
            'double' => ['double', 'DOUBLE'],
            'datetime' => ['datetime', 'DATETIME'],
            'mediumtext' => ['mediumtext', 'MEDIUMTEXT'],
            'longtext' => ['longtext', 'LONGTEXT'],
            'blob' => ['blob', 'BLOB'],
            'mediumblob' => ['mediumblob', 'MEDIUMBLOB'],
            'longblob' => ['longblob', 'LONGBLOB'],
            'json' => ['json', 'JSON'],
            'boolean' => ['boolean', 'BOOLEAN'],
            'int' => ['int', 'INT', false],
            // the keyword regex only widens int->bigint when the text says
            // "UNSIGNED" - declarativeTypeToPostgres needs the same flag passed
            // explicitly, so both sides of this case carry it.
            'int unsigned widens on both paths' => ['int', 'INT UNSIGNED', true],
        ];
    }
}
