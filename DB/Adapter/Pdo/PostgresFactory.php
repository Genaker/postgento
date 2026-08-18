<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Adapter\Pdo;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\ObjectManagerInterface;

/**
 * Magento\Framework\DB\Adapter\Pdo\MysqlFactory (used by the framework's own
 * Type\Db\Pdo\Mysql resource-connection wrapper) validates that the requested class
 * extends Magento\Framework\DB\Adapter\Pdo\Mysql - not true for Postgres (extends
 * Zend_Db_Adapter_Pdo_Pgsql instead, see that class for why), so that factory would
 * reject it outright. This one validates the actual contract Postgres implements -
 * AdapterInterface - instead of one specific inheritance lineage.
 */
class PostgresFactory
{
    public function __construct(private readonly ObjectManagerInterface $objectManager)
    {
    }

    /**
     * @param string $className
     * @param array $config
     * @param LoggerInterface|null $logger
     * @param SelectFactory|null $selectFactory
     * @return AdapterInterface
     */
    public function create(
        $className,
        array $config,
        ?LoggerInterface $logger = null,
        ?SelectFactory $selectFactory = null
    ) {
        if (!is_a($className, AdapterInterface::class, true)) {
            throw new \InvalidArgumentException(
                'Invalid class, ' . $className . ' must implement ' . AdapterInterface::class . '.'
            );
        }
        $arguments = ['config' => $config];
        if ($logger) {
            $arguments['logger'] = $logger;
        }
        if ($selectFactory) {
            $arguments['selectFactory'] = $selectFactory;
        }
        return $this->objectManager->create($className, $arguments);
    }
}
