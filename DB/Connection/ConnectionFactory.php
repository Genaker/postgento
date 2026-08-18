<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Connection;

/**
 * Normalizes Magento's MySQL-shaped connection config array into a Postgres DSN and
 * opens the PDO connection - kept separate from the adapter so connection setup can be
 * reasoned about (and, if needed, tested) without the rest of the adapter's behavior.
 */
final class ConnectionFactory
{
    /**
     * Strips MySQL-only config keys and splits a "host:port" value the way Magento's
     * env.php always encodes it, since Postgres config has no separate `port` key.
     */
    public function normalize(array $config): array
    {
        unset(
            $config['model'],
            $config['engine'],
            $config['active'],
            $config['type'],
            $config['driver_options'][\PDO::MYSQL_ATTR_MULTI_STATEMENTS]
        );

        if (isset($config['port'])) {
            throw new \Zend_Db_Adapter_Exception('Port must be configured within host parameter (like localhost:5432)');
        }
        if (isset($config['host']) && strpos((string) $config['host'], ':') !== false) {
            [$config['host'], $config['port']] = explode(':', (string) $config['host'], 2);
        }
        return $config;
    }

    public function dsn(array $normalizedConfig): string
    {
        $dsn = sprintf(
            'pgsql:host=%s;dbname=%s',
            $normalizedConfig['host'] ?? '',
            $normalizedConfig['dbname'] ?? ''
        );
        if (!empty($normalizedConfig['port'])) {
            $dsn .= ';port=' . $normalizedConfig['port'];
        }
        return $dsn;
    }

    public function connect(array $normalizedConfig): \PDO
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new \Zend_Db_Adapter_Exception('pdo_pgsql extension is not installed');
        }
        if (!isset($normalizedConfig['host'])) {
            throw new \Zend_Db_Adapter_Exception('No host configured to connect');
        }

        try {
            $connection = new \PDO(
                $this->dsn($normalizedConfig),
                $normalizedConfig['username'] ?? '',
                $normalizedConfig['password'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES => true,
                    \PDO::ATTR_STRINGIFY_FETCHES => true,
                ]
            );
        } catch (\PDOException $e) {
            throw new \Zend_Db_Adapter_Exception($e->getMessage(), (int) $e->getCode(), $e);
        }

        $connection->query("SET TIME ZONE 'UTC'");
        $connection->query("SET client_encoding TO 'UTF8'");
        return $connection;
    }
}
