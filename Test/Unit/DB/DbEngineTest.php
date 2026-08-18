<?php

declare(strict_types=1);

namespace Morozov\PgCompat\Test\Unit\DB;

use Morozov\PgCompat\DB\DbEngine;
use PHPUnit\Framework\TestCase;

class DbEngineTest extends TestCase
{
    /**
     * @dataProvider configProvider
     */
    public function testIsPostgres(array $config, bool $expected): void
    {
        $this->assertSame($expected, DbEngine::isPostgres($config));
    }

    public static function configProvider(): array
    {
        return [
            'innodb still mysql' => [['engine' => 'innodb'], false],
            'mysql' => [['engine' => 'mysql'], false],
            'empty' => [[], false],
            'postgresql' => [['engine' => 'postgresql'], true],
            'postgres' => [['engine' => 'postgres'], true],
            'pgsql' => [['engine' => 'pgsql'], true],
            'type pdo_pgsql' => [['type' => 'pdo_pgsql'], true],
            'mysql host' => [['host' => 'mysql'], false],
            'postgres host' => [['host' => 'postgres'], true],
            'postgres host port' => [['host' => 'postgres:5432'], true],
        ];
    }

    public function testMysqlAdapterConfigMapsRdbmsNameToInnodb(): void
    {
        $config = DbEngine::mysqlAdapterConfig(['engine' => 'mysql', 'host' => 'db']);
        $this->assertSame('innodb', $config['engine']);
        $this->assertSame('db', $config['host']);
    }

    public function testMysqlAdapterConfigLeavesInnodb(): void
    {
        $config = DbEngine::mysqlAdapterConfig(['engine' => 'innodb']);
        $this->assertSame('innodb', $config['engine']);
    }
}
