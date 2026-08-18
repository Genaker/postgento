<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Connection;

/**
 * MySQL coerces '' to 0 in numeric comparisons; Postgres does not. Magento collections
 * pass an empty string through addFieldToFilter()/prepareSqlCondition() as "no id yet"
 * (entity not saved). Applied at that seam so query() does not regex every statement.
 */
final class EmptyIdCondition
{
    public function isIdField(string $fieldName): bool
    {
        $bare = strtolower(str_replace(['"', '`'], '', $fieldName));
        $bare = preg_replace('/\s*(=|!=|<>|>=|<=|>|<|in\b|not\s+in\b).*/i', '', $bare) ?? $bare;
        $bare = trim($bare, " \t()");
        $dot = strrpos($bare, '.');
        if ($dot !== false) {
            $bare = substr($bare, $dot + 1);
        }
        return $bare !== '' && str_ends_with($bare, '_id');
    }

    public function coerceEqOrGt(string $fieldName, mixed $value): mixed
    {
        if ($this->isIdField($fieldName) && $this->isEmptyIdValue($value)) {
            return 0;
        }
        return $value;
    }

    public function isEmptyInList(string $fieldName, mixed $value): bool
    {
        if (!$this->isIdField($fieldName)) {
            return false;
        }
        if ($this->isEmptyIdValue($value)) {
            return true;
        }
        if (!is_array($value) || $value === []) {
            return false;
        }
        foreach ($value as $item) {
            if (!$this->isEmptyIdValue($item)) {
                return false;
            }
        }
        return true;
    }

    private function isEmptyIdValue(mixed $value): bool
    {
        return $value === '' || $value === false || $value === null;
    }
}
