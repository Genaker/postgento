<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB;

use Magento\Framework\App\ResourceConnection\ConnectionAdapterInterface;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\ObjectManagerInterface;

/**
 * Picks the resource-connection wrapper from env.php: Postgres when engine is
 * postgresql/postgres/pgsql, MySQL when engine is mysql (Magento Type\Db\Pdo\Mysql
 * → MysqlCompat so patched core can call getFieldSql / getGroupConcatSql / …).
 */
class ConnectionAdapter implements ConnectionAdapterInterface
{
    private readonly ConnectionAdapterInterface $inner;

    public function __construct(array $config, ObjectManagerInterface $objectManager)
    {
        $this->inner = DbEngine::isPostgres($config)
            ? $objectManager->create(Postgres::class, ['config' => $config])
            : $objectManager->create(
                \Magento\Framework\Model\ResourceModel\Type\Db\Pdo\Mysql::class,
                ['config' => DbEngine::mysqlAdapterConfig($config)]
            );
    }

    public function getConnection(?LoggerInterface $logger = null, ?SelectFactory $selectFactory = null)
    {
        return $this->inner->getConnection($logger, $selectFactory);
    }
}
