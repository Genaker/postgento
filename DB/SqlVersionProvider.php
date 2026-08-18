<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB;

use Magento\Framework\App\ResourceConnection;

class SqlVersionProvider extends \Magento\Framework\DB\Adapter\SqlVersionProvider
{
    public function __construct(
        ResourceConnection $resourceConnection,
        private readonly EngineResolver $engine,
        array $supportedVersionPatterns = []
    ) {
        parent::__construct($resourceConnection, $supportedVersionPatterns);
    }

    public function getSqlVersion(string $resource = ResourceConnection::DEFAULT_CONNECTION): string
    {
        if ($this->engine->isPostgres()) {
            return self::MYSQL_8_4_VERSION . '0';
        }
        return parent::getSqlVersion($resource);
    }

    public function isMysqlGte8029(): bool
    {
        return $this->engine->isPostgres() ? true : parent::isMysqlGte8029();
    }

    public function isMariaDbEngine(): bool
    {
        return $this->engine->isPostgres() ? false : parent::isMariaDbEngine();
    }

    public function getMariaDbSuffixKey(): string
    {
        return $this->engine->isPostgres() ? self::MARIA_DB_10_6_11_VERSION : parent::getMariaDbSuffixKey();
    }
}
