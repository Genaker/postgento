# Postgento (`Morozov_PgCompat`)

This is a fork of Kirill Morozov’s Magento PostgreSQL compatibility module (`Morozov_PgCompat`). It is a PostgreSQL adapter for Magento Open Source / [Mage-OS](https://github.com/mage-os/mageos-magento2). Magento still runs on MySQL by default; this module adds a native Postgres engine selected from `env.php` (`db/connection/default/engine`).

## Why Postgres instead of Magento’s MySQL

Magento treats the database as a dumb InnoDB store plus a separate OpenSearch cluster. Postgres is a platform Magento can actually grow into:

- **Search in the database** — [ParadeDB](https://www.paradedb.com/) (BM25 / `pg_search`) and `pg_trgm` / full text, instead of keeping Elasticsearch/OpenSearch as a mandatory second brain for catalog search.
- **GIS** — [PostGIS](https://postgis.net/) for store locators, delivery zones, and geo inventory. MySQL’s spatial types are a thin afterthought.
- **Procedures in real languages** — PL/pgSQL, PL/Python, PL/v8, SQL/PSM. Magento’s MySQL stored-routine story is `DELIMITER //` and pain.
- **Extensions** — `citext`, `pg_trgm`, `pgvector`, `uuid-ossp`, foreign data wrappers, logical replication. Install an extension; don’t wait for a Magento module to reinvent it in PHP.
- **Admin that isn’t MySQL Workbench / phpMyAdmin** — [pgAdmin](https://www.pgadmin.org/), `psql`, and a query planner you can read. Magento’s MySQL tooling is the 2009 LAMP leftover.

MySQL stays supported so existing shops don’t break. Postgres is the reason this module exists.

## Core PRs (required for Magento SQL)

Portable SQL does **not** belong in this module long-term. It is a core change. The same change set is open on both distributions:

- **Mage-OS:** https://github.com/mage-os/mageos-magento2/pull/321
- **Magento Open Source:** https://github.com/magento/magento2/pull/41129

Those PRs add `AdapterInterface` helpers (`getGroupConcatSql`, `getFieldSql`, `castToText`, `castToNumeric`, `createTableLike`, `createTemporaryTableFromSelect`) and replace MySQL-only SQL at Magento call sites (`IFNULL`, backticks, `GROUP_CONCAT`, `FIELD()`, `UNION` casts, `ON DUPLICATE KEY` text, `UPDATE … LIMIT`, and so on). Magento `2.4-develop` already differs from Mage-OS in a few files; the Magento PR keeps Adobe’s versions of those files.

### After a core PR is merged

On **Mage-OS** or **Magento Open Source** (any tree that contains those commits):

- **Do not apply** the Magento SQL patches under `patches/` that only rewrite core call sites.
- Core already exposes the dialect helpers; this module only needs to implement them on the Postgres adapter (`DB\Adapter\Pdo\Postgres`).
- `MysqlCompat` is unnecessary once `Pdo\Mysql` has those methods — you can drop the `Pdo\Mysql` preference in `etc/di.xml`.

You still install **this module** for the Postgres driver, declarative schema, installer `ConnectionFactory`, sequence `RETURNING` / `IDENTITY`, and related setup. A few installer/session patches listed below still apply.

### Until those PRs are merged

On **raw Magento Open Source** and current Mage-OS `main`, you **must** apply the diffs in `patches/` (or check out a PR branch). Those files are the same call-site changes as Mage-OS #321 / Magento #41129. `MysqlCompat` implements the new adapter methods so MySQL still works with those patches applied.

## Using the patches (raw Magento, Mage-OS, Adobe)

Patches are unified diffs against the **Magento 2 git tree** (`app/code/Magento/...`, `lib/internal/Magento/Framework/...`, `setup/src/...`) — the layout of [magento/magento2](https://github.com/magento/magento2) and [mage-os/mageos-magento2](https://github.com/mage-os/mageos-magento2). They are **not** written for split Composer packages (`vendor/magento/module-catalog/...`) unless you remap paths.

### 1. Git Magento / Mage-OS clone (recommended today)

From the Magento root, after this module is in `vendor/genaker/module-postgento` or `app/code/Morozov/PgCompat`:

```bash
# example: module in vendor
PATCHDIR=vendor/genaker/module-postgento/patches
for p in "$PATCHDIR"/*.patch; do
  patch -p1 --forward --no-backup-if-mismatch < "$p" || echo "FAIL $p"
done
```

Or register them with [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches) on the metapackage name your root `composer.json` uses (`magento/magento2ce` or `mage-os/magento2ce`):

```json
{
  "require": {
    "cweagans/composer-patches": "^1.7"
  },
  "config": {
    "allow-plugins": {
      "cweagans/composer-patches": true
    }
  },
  "extra": {
    "composer-exit-on-patch-failure": true,
    "patches": {
      "magento/magento2ce": {
        "Postgento: Select AdapterInterface typehint": "vendor/genaker/module-postgento/patches/select-adapterinterface-typehint.patch",
        "Postgento: IFNULL eraser": "vendor/genaker/module-postgento/patches/ifnull-eraser-getifnullsql.patch"
      }
    }
  }
}
```

List **every** file in `patches/` the same way (one extra.patches entry per file). `composer install` / `composer update` applies them. Sample data uses a different package key:

```json
"magento/module-catalog-sample-data": {
  "Postgento: skip options before attribute id": "vendor/genaker/module-postgento/patches/sampledata-empty-attribute-id.patch"
}
```

### 2. Composer Magento (`magento/project-community-edition`)

`composer create-project magento/project-community-edition` installs **split packages**. These patch files will not apply as-is (`app/code/Magento/Catalog/...` vs `vendor/magento/module-catalog/...`). Options:

- Develop against a **git** Magento/Mage-OS checkout (above), or
- Wait for Mage-OS #321 or Magento #41129 (then you only need the **module-only** patches, still git-path), or
- Re-root each hunk onto `vendor/magento/module-*` / `vendor/magento/framework` / `vendor/magento/framework-setup` yourself.

### 3. After a core PR is in your tree — keep only module patches

Skip the SQL call-site patches (IFNULL, GROUP_CONCAT, backticks, UNION casts, FIELD(), temp table, etc.). Still apply patches that talk to this module / Postgres setup:

| Patch | Why it stays |
|---|---|
| `connectionfactory-postgres.patch` | Setup CLI opens `pdo_pgsql` |
| `installer-cleanup-schema.patch` | `--cleanup-database` → `DROP SCHEMA public` |
| `sequence-ddl-identity.patch` | Sales sequence `IDENTITY` vs `AUTO_INCREMENT` |
| `setup-pass-db-engine.patch` | Pass `--db-engine` into DB validator |
| `batchsizemanagement-noop.patch` | No `max_heap_table_size` on Postgres |
| `maxheaptablesizeprocessor-noop.patch` | Same |
| `unique-checks-operations-executor.patch` | No `UNIQUE_CHECKS` on Postgres |
| `show-variables-importexport-max-packet.patch` | No `max_allowed_packet` |
| `show-variables-fixture-autoincrement.patch` | No `auto_increment_increment` |

If a hunk is already in core (e.g. Mage-OS or Magento merged `setup-pass-db-engine`), skip that file.

## Install

PHP: `pdo_pgsql` (and `pdo_mysql` if you still run MySQL). Postgres 16 is what we test. OpenSearch/Elasticsearch is still Magento’s catalog search until you replace it (e.g. ParadeDB).

```bash
composer require genaker/module-postgento
bin/magento module:enable Morozov_PgCompat
```

Until Packagist:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Genaker/postgento" }
  ]
}
```

```bash
composer require genaker/module-postgento:dev-main
```

You can also copy this repo to `app/code/Morozov/PgCompat` (no Composer package). Apply patches **before** `setup:install` on a raw Magento tree.

Postgres install (empty database; `model` stays `mysql4`):

```bash
bin/magento setup:install \
  --db-engine=postgresql \
  --db-host=127.0.0.1 \
  --db-name=magento \
  --db-user=magento \
  --db-password=magento \
  --backend-frontname=admin \
  --search-engine=opensearch \
  ...
```

MySQL: `--db-engine=mysql`. Then `bin/magento indexer:reindex` and `bin/magento cache:flush`.

Failed Postgres statements: `var/log/sqltopostgres.log`.

## Architecture (short)

- `postgresql` / `postgres` / `pgsql` → `DB\Postgres` → `DB\Adapter\Pdo\Postgres` (`Zend_Db_Adapter_Pdo_Pgsql`). Does not extend Magento’s MySQL adapter.
- `mysql` → Magento `Pdo\Mysql` (or `MysqlCompat` until a core PR lands).

## Tests

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Morozov/PgCompat/Test/Unit
```

(When the module is in `vendor/genaker/module-postgento`, point PHPUnit at that path.)
