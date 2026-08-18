<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB\Connection;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Morozov\PgCompat\DB\Connection\QueryRewriter;
use Morozov\PgCompat\DB\Dialect\UpsertBuilder;
use PHPUnit\Framework\TestCase;

class QueryRewriterTest extends TestCase
{
    private QueryRewriter $rewriter;

    private AdapterInterface $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->rewriter = new QueryRewriter(new UpsertBuilder());
    }

    public function testShowVariablesIsNotRewritten(): void
    {
        $sql = 'SHOW VARIABLES LIKE "max_allowed_packet"';
        $this->assertSame($sql, $this->rewriter->rewrite($sql, $this->adapter));
    }

    public function testEmptyIdComparisonIsNotRewritten(): void
    {
        $sql = "SELECT * FROM quote WHERE entity_id=''";
        $this->assertSame($sql, $this->rewriter->rewrite($sql, $this->adapter));
    }
}
