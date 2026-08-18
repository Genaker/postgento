# Postgento (`Morozov_PgCompat`)

PostgreSQL adapter for Magento Open Source / [Mage-OS](https://github.com/mage-os/mageos-magento2). Magento still runs on MySQL by default; this module adds a native Postgres engine selected from `env.php` (`db/connection/default/engine`).

## Mage-OS core PR (required for Magento SQL)

Portable SQL does **not** belong in this module long-term. It is a core change:

**https://github.com/mage-os/mageos-magento2/pull/321**

That PR adds `AdapterInterface` helpers (`getGroupConcatSql`, `getFieldSql`, `castToText`, `castToNumeric`, `createTableLike`, `createTemporaryTableFromSelect`) and replaces MySQL-only SQL at Magento call sites (`IFNULL`, backticks, `GROUP_CONCAT`, `FIELD()`, `UNION` casts, `ON DUPLICATE KEY` text, `UPDATE … LIMIT`, and so on).

### After #321 is merged

On **Mage-OS** (and any Magento tree that contains those commits):

- **Do not apply** the Magento SQL patches under `patches/` that only rewrite core call sites.
- Core already exposes the dialect helpers; this module only needs to implement them on the Postgres adapter (`DB\Adapter\Pdo\Postgres`).
- `MysqlCompat` is unnecessary once `Pdo\Mysql` has those methods — you can drop the `Pdo\Mysql` preference in `etc/di.xml`.

You still install **this module** for the Postgres driver, declarative schema, installer `ConnectionFactory`, sequence `RETURNING` / `IDENTITY`, and related setup.

### Until #321 is merged

On stock Magento / current Mage-OS `main`, keep applying the patches in `patches/` (via `cweagans/composer-patches` or by using the PR branch). Those diffs are what PR 321 upstreams. `MysqlCompat` supplies the helper methods so MySQL still works with the same patches.

Patches that **stay with this module** even after merge (they reference the adapter, not Magento SQL):

- `connectionfactory-postgres.patch`
- `installer-cleanup-schema.patch`
- `sequence-ddl-identity.patch`
- `setup-pass-db-engine.patch` (if Mage-OS does not already pass `--db-engine`)
- `batchsizemanagement-noop.patch`
- `maxheaptablesizeprocessor-noop.patch`
- `unique-checks-operations-executor.patch`
- `show-variables-importexport-max-packet.patch`
- `show-variables-fixture-autoincrement.patch`

## Install

```bash
composer require genaker/module-postgento
bin/magento module:enable Morozov_PgCompat
bin/magento setup:upgrade
```

Until the package is on Packagist, require the GitHub repo:

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

PHP needs `pdo_pgsql`. Install Magento with:

```bash
bin/magento setup:install --db-engine=postgresql --db-host=postgres ...
```

`model` stays `mysql4`. MySQL remains `--db-engine=mysql`.

## Architecture (short)

- `postgresql` / `postgres` / `pgsql` → `DB\Postgres` → `DB\Adapter\Pdo\Postgres` (`Zend_Db_Adapter_Pdo_Pgsql`). Does not extend Magento’s MySQL adapter.
- `mysql` → Magento `Pdo\Mysql` (or `MysqlCompat` until #321 lands).

Failed statements that reach Postgres are logged to `var/log/sqltopostgres.log`.

## Tests

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Morozov/PgCompat/Test/Unit
```

(When the module is in `vendor/genaker/module-postgento`, point PHPUnit at that path.)
