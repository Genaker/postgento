<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Dialect;

/**
 * Postgres identifier/value quoting shared by every dialect builder.
 *
 * Kept dependency-free (no adapter/PDO) so the DDL builders can be unit tested
 * without a database connection.
 */
final class PgIdentifier
{
    public static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public static function quoteList(array $identifiers): string
    {
        return implode(', ', array_map([self::class, 'quote'], $identifiers));
    }

    public static function quoteValue(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
