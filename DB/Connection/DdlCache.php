<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Connection;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Magento AdapterInterface DDL cache (describe/index/FK), in-memory plus optional
 * Magento cache frontend. Keys must use schema 'public' when callers pass null.
 */
final class DdlCache
{
    public const PREFIX = 'DB_PDO_PGSQL_DDL';
    public const TAG = 'DB_PDO_PGSQL_DDL';

    private array $ddlCache = [];

    private bool $allowed = true;

    private ?FrontendInterface $cacheAdapter = null;

    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    public function setCacheAdapter(FrontendInterface $cacheAdapter): void
    {
        $this->cacheAdapter = $cacheAdapter;
    }

    public function allow(): void
    {
        $this->allowed = true;
    }

    public function disallow(): void
    {
        $this->allowed = false;
    }

    public function load(string $tableCacheKey, int $ddlType): mixed
    {
        if (!$this->allowed) {
            return false;
        }
        if (isset($this->ddlCache[$ddlType][$tableCacheKey])) {
            return $this->ddlCache[$ddlType][$tableCacheKey];
        }
        if ($this->cacheAdapter) {
            $data = $this->cacheAdapter->load($this->cacheId($tableCacheKey, $ddlType));
            if ($data !== false) {
                $data = $this->serializer->unserialize($data);
                $this->ddlCache[$ddlType][$tableCacheKey] = $data;
            }
            return $data;
        }
        return false;
    }

    public function save(string $tableCacheKey, int $ddlType, mixed $data): void
    {
        if (!$this->allowed) {
            return;
        }
        $this->ddlCache[$ddlType][$tableCacheKey] = $data;
        if ($this->cacheAdapter) {
            $this->cacheAdapter->save(
                $this->serializer->serialize($data),
                $this->cacheId($tableCacheKey, $ddlType),
                [self::TAG]
            );
        }
    }

    /**
     * @param int[] $ddlTypes
     */
    public function reset(?string $tableName, ?string $schemaName, array $ddlTypes): void
    {
        if (!$this->allowed) {
            return;
        }
        if ($tableName === null) {
            $this->ddlCache = [];
            if ($this->cacheAdapter) {
                $this->cacheAdapter->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [self::TAG]);
            }
            return;
        }
        $cacheKey = ($schemaName ?: 'public') . '.' . $tableName;
        foreach ($ddlTypes as $ddlType) {
            unset($this->ddlCache[$ddlType][$cacheKey]);
            if ($this->cacheAdapter) {
                $this->cacheAdapter->remove($this->cacheId($cacheKey, $ddlType));
            }
        }
    }

    private function cacheId(string $tableCacheKey, int $ddlType): string
    {
        return sprintf('%s_%s_%s', self::PREFIX, $tableCacheKey, $ddlType);
    }
}
