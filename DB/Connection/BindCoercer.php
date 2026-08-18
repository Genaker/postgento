<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Connection;

/**
 * MySQL silently coerces PHP values on bind (booleans to 0/1, '' to 0 for numeric
 * columns, floats truncated into int columns); Postgres' typed driver does not and
 * rejects the mismatch outright. Core relies on the MySQL coercion in a lot of places
 * (boolean config flags bound as PHP bool, '' used as an unset-numeric sentinel), so
 * this reproduces it ahead of bind time using the column's real type from describeTable().
 *
 * Pure function of (describe array, bind array) - no adapter/connection dependency -
 * so it's straightforward to unit test independent of a live database.
 */
final class BindCoercer
{
    private const NUMERIC_TYPES = ['smallint', 'int', 'integer', 'bigint', 'tinyint'];
    private const EMPTY_STRING_ZERO_TYPES = ['smallint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'real', 'tinyint'];
    private const TIMESTAMP_TYPES = ['timestamp', 'datetime'];
    private const TIMESTAMP_COLUMN_NAMES = ['update_time', 'created_at', 'updated_at', 'creation_time'];

    public function coerce(array $describe, array $bind): array
    {
        foreach ($bind as $col => $value) {
            if ($value === false) {
                $bind[$col] = 0;
                continue;
            }
            if ($value === true) {
                $bind[$col] = 1;
                continue;
            }
            $type = strtolower((string) ($describe[$col]['DATA_TYPE'] ?? ''));
            if ($value !== null && $value !== ''
                && is_numeric($value)
                && in_array($type, self::NUMERIC_TYPES, true)
                && (is_float($value) || str_contains((string) $value, '.'))
            ) {
                $bind[$col] = (int) round((float) $value);
                continue;
            }
            if ($value !== null && $value !== '') {
                continue;
            }
            $isTimestamp = in_array($type, self::TIMESTAMP_TYPES, true)
                || in_array($col, self::TIMESTAMP_COLUMN_NAMES, true);
            if ($isTimestamp) {
                $bind[$col] = date('Y-m-d H:i:s');
                continue;
            }
            if ($value === '' && in_array($type, self::EMPTY_STRING_ZERO_TYPES, true)) {
                $bind[$col] = 0;
            }
        }
        return $bind;
    }

    public function omitNullIdentity(array $describe, array $bind): array
    {
        foreach ($bind as $col => $value) {
            if (($value === null || $value === '') && !empty($describe[$col]['IDENTITY'])) {
                unset($bind[$col]);
            }
        }
        return $bind;
    }
}
