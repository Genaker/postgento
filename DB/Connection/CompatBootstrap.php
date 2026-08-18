<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Connection;

/**
 * Once-per-connection Postgres extras Magento's SQL assumes exist: round(float, int)
 * overloads and citext on catalog_product_entity.sku.
 */
final class CompatBootstrap
{
    private bool $ready = false;

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function markDirty(): void
    {
        $this->ready = false;
    }

    public function ensure(\PDO $connection, callable $onSkuAltered): void
    {
        if ($this->ready) {
            return;
        }
        try {
            $connection->exec(
                'CREATE OR REPLACE FUNCTION round(double precision, integer)
                 RETURNS numeric LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
                 $$ SELECT ROUND($1::numeric, $2) $$'
            );
            $connection->exec(
                'CREATE OR REPLACE FUNCTION round(real, integer)
                 RETURNS numeric LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
                 $$ SELECT ROUND($1::numeric, $2) $$'
            );
            $connection->exec('CREATE EXTENSION IF NOT EXISTS citext');
            $exists = $connection->query(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'catalog_product_entity'
                   AND column_name = 'sku' AND udt_name <> 'citext'"
            )->fetchColumn();
            if ($exists) {
                $connection->exec(
                    'ALTER TABLE catalog_product_entity ALTER COLUMN sku TYPE citext USING sku::citext'
                );
                $onSkuAltered();
            }
            $this->ready = true;
        } catch (\Exception $e) {
            $this->ready = false;
        }
    }
}
