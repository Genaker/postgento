<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Dialect;

/**
 * Postgres identifiers are capped at 63 bytes, unlike MySQL/InnoDB's much longer limits,
 * and Magento constraint/index names are frequently table-name-agnostic (e.g. "UNQ_SKU"),
 * which collide once every table shares one flat schema namespace. This is the single
 * place that both shortens names and scopes them to their owning table.
 */
final class NameResolver
{
    private const MAX_LENGTH = 63;

    public function fit(string $name): string
    {
        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }
        return substr($name, 0, 55) . '_' . substr(md5($name), 0, 7);
    }

    public function constraintName(string $tableName, string $rawName): string
    {
        return $this->fit($tableName . '_' . $rawName);
    }

    public function relationName(string $rawName, ?string $tableName): string
    {
        $name = $tableName ? $tableName . '_' . $rawName : $rawName;
        return $this->fit($name);
    }
}
