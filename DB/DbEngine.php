<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB;

/**
 * Connection-config discriminator. Magento still writes model=mysql4 for both
 * engines; engine/type/driver is the switch:
 *   postgresql|postgres|pgsql  → native Postgres adapter
 *   mysql                      → Magento MySQL adapter
 *
 * Magento's stock --db-engine default is the InnoDB *storage* engine name.
 * That is not the RDBMS selector; use mysql / postgresql. innodb still selects
 * MySQL so an unpatched Magento env.php keeps working.
 */
final class DbEngine
{
    public static function isPostgres(array $config): bool
    {
        $keys = [
            strtolower((string) ($config['engine'] ?? '')),
            strtolower((string) ($config['type'] ?? '')),
            strtolower((string) ($config['driver'] ?? '')),
            strtolower((string) ($config['pdoType'] ?? '')),
        ];
        foreach ($keys as $value) {
            if (in_array($value, ['postgresql', 'postgres', 'pgsql', 'pdo_pgsql', 'pdo_postgres'], true)) {
                return true;
            }
        }
        $host = strtolower((string) ($config['host'] ?? ''));
        $host = explode(':', $host, 2)[0];
        return in_array($host, ['postgres', 'postgresql', 'pgsql'], true);
    }

    /**
     * Magento's MySQL adapter uses config engine as CREATE TABLE … ENGINE=.
     * env.php engine names the RDBMS (mysql); storage engine stays InnoDB.
     */
    public static function mysqlAdapterConfig(array $config): array
    {
        $engine = strtolower((string) ($config['engine'] ?? ''));
        if ($engine === 'mysql' || $engine === 'mariadb') {
            $config['engine'] = 'innodb';
        }
        return $config;
    }
}
