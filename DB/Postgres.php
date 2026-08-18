<?php

namespace Morozov\PgCompat\DB;

use Magento\Framework\App\ResourceConnection\ConnectionAdapterInterface;
use Magento\Framework\DB;
use Magento\Framework\DB\SelectFactory;
use Morozov\PgCompat\DB\Adapter\Pdo\PostgresFactory;

/**
 * Resource-connection wrapper for PostgreSQL. Selected by ConnectionAdapter when
 * env.php db/connection/default engine is postgresql|postgres|pgsql.
 */
class Postgres extends \Magento\Framework\Model\ResourceModel\Type\Db implements ConnectionAdapterInterface
{
    protected $connectionConfig;

    public function __construct(array $config, private readonly PostgresFactory $postgresFactory)
    {
        $this->connectionConfig = $this->getValidConfig($config);
        parent::__construct();
    }

    public function getConnection(?DB\LoggerInterface $logger = null, ?SelectFactory $selectFactory = null)
    {
        $connection = $this->getDbConnectionInstance($logger, $selectFactory);

        $profiler = $connection->getProfiler();
        if ($profiler instanceof DB\Profiler) {
            $profiler->setType($this->connectionConfig['type']);
            $profiler->setHost($this->connectionConfig['host']);
        }

        return $connection;
    }

    protected function getDbConnectionInstance(?DB\LoggerInterface $logger = null, ?SelectFactory $selectFactory = null)
    {
        return $this->postgresFactory->create(
            Adapter\Pdo\Postgres::class,
            $this->connectionConfig,
            $logger,
            $selectFactory
        );
    }

    private function getValidConfig(array $config)
    {
        $default = ['initStatements' => 'SET NAMES utf8', 'type' => 'pdo_postgres', 'active' => false];
        foreach ($default as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }
        $required = ['host'];
        foreach ($required as $name) {
            if (!isset($config[$name])) {
                throw new \InvalidArgumentException("Postgres adapter: Missing required configuration option '$name'");
            }
        }

        if (isset($config['port'])) {
            throw new \InvalidArgumentException(
                "Port must be configured within host (like '$config[host]:$config[port]') parameter, not within port"
            );
        }

        $config['active'] = !(
            $config['active'] === 'false'
            || $config['active'] === false
            || $config['active'] === '0'
        );

        return $config;
    }
}
