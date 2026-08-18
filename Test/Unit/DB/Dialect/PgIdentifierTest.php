<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB\Dialect;

use Morozov\PgCompat\DB\Dialect\PgIdentifier;
use PHPUnit\Framework\TestCase;

class PgIdentifierTest extends TestCase
{
    public function testQuoteWrapsInDoubleQuotes(): void
    {
        $this->assertSame('"column"', PgIdentifier::quote('column'));
    }

    public function testQuoteDoublesEmbeddedQuotes(): void
    {
        $this->assertSame('"weird""col"', PgIdentifier::quote('weird"col'));
    }

    public function testQuoteListJoinsMultipleIdentifiers(): void
    {
        $this->assertSame('"a", "b", "c"', PgIdentifier::quoteList(['a', 'b', 'c']));
    }

    public function testQuoteListOnEmptyArray(): void
    {
        $this->assertSame('', PgIdentifier::quoteList([]));
    }

    public function testQuoteValueWrapsInSingleQuotes(): void
    {
        $this->assertSame("'value'", PgIdentifier::quoteValue('value'));
    }

    public function testQuoteValueDoublesEmbeddedSingleQuotes(): void
    {
        $this->assertSame("'it''s'", PgIdentifier::quoteValue("it's"));
    }

    public function testQuoteValueOnEmptyString(): void
    {
        $this->assertSame("''", PgIdentifier::quoteValue(''));
    }
}
