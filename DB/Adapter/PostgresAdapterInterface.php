<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Adapter;

/**
 * Empty marker interface implemented by DB\Adapter\Pdo\Postgres, for
 * core-framework composer patches that need an `instanceof`-checkable way to
 * say "skip this MySQL-only step" without hardcoding a specific class name
 * (which would silently stop matching if the adapter's inheritance chain
 * ever changes).
 *
 * First use: lib/internal/Magento/Framework/Setup/Declaration/Schema/
 * OperationsExecutor.php's UNIQUE_CHECKS toggle (see
 * unique-checks-operations-executor.patch) - a MySQL bulk-schema-change
 * optimization with no Postgres equivalent (Postgres always fully enforces
 * uniqueness; there is no "disable and re-verify later" mode short of dropping and
 * recreating the constraint), so the toggle is skipped outright rather than
 * translated.
 */
interface PostgresAdapterInterface
{
}
