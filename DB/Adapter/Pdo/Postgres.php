<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Adapter\Pdo;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Morozov\PgCompat\DB\Adapter\PostgresAdapterInterface;
use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\DB\Adapter\TableNotFoundException;
use Magento\Framework\DB\ExpressionConverter;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Ddl\Trigger;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\Query\Generator as QueryGenerator;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Setup\SchemaListener;
use Magento\Framework\Stdlib\StringUtils;
use Morozov\PgCompat\DB\Connection\BindCoercer;
use Morozov\PgCompat\DB\Connection\CompatBootstrap;
use Morozov\PgCompat\DB\Connection\ConnectionFactory;
use Morozov\PgCompat\DB\Connection\DdlCache;
use Morozov\PgCompat\DB\Connection\ExceptionMapper;
use Morozov\PgCompat\DB\Connection\IdentitySynchronizer;
use Morozov\PgCompat\DB\Connection\QueryRewriter;
use Morozov\PgCompat\DB\Dialect\ColumnDefinitionBuilder;
use Morozov\PgCompat\DB\Dialect\NameResolver;
use Morozov\PgCompat\DB\Dialect\PgIdentifier;
use Morozov\PgCompat\DB\Dialect\SelectWriteSql;
use Morozov\PgCompat\DB\Dialect\SqlExpressions;
use Morozov\PgCompat\DB\Dialect\TableSql;
use Morozov\PgCompat\DB\Dialect\TypeMapper;
use Morozov\PgCompat\DB\Dialect\UpsertBuilder;
use Morozov\PgCompat\DB\Introspection\SchemaIntrospector;

/**
 * Native Postgres adapter for Magento's DB layer - the sole adapter this module
 * provides (see DB\Postgres's own docblock for the resource-connection wrapper
 * that opens it).
 *
 * Extends Zend's own Postgres lineage (Zend_Db_Adapter_Pdo_Pgsql -> ...Pdo_Abstract ->
 * ...Abstract) and implements Magento's AdapterInterface directly, rather than
 * inheriting Magento\Framework\DB\Adapter\Pdo\Mysql and overriding what differs. This
 * is the shape Zend_Db's own multi-database architecture was designed around (a
 * Pdo_Mysql adapter and a Pdo_Pgsql adapter as siblings under one Abstract base), but
 * Magento's AdapterInterface - the ~94-method contract this whole framework actually
 * calls through, added on top of bare Zend_Db - has no Postgres implementation
 * anywhere in that lineage, so every one of those methods is implemented fresh here
 * instead. ~20 of them (query/insert/update/delete/fetchAll/fetchRow/quoteIdentifier/
 * quoteInto/transactions) ARE already portable via Zend_Db_Adapter_Abstract and are
 * only lightly wrapped below.
 *
 * Postgres SQL generation lives in Dialect/Connection/Introspection collaborators.
 * Public AdapterInterface methods stay here as Magento's call surface.
 */
class Postgres extends \Zend_Db_Adapter_Pdo_Pgsql implements AdapterInterface, PostgresAdapterInterface
{
    public const DDL_DESCRIBE = 1;
    public const DDL_CREATE = 2;
    public const DDL_INDEX = 3;
    public const DDL_FOREIGN_KEY = 4;
    private const DDL_EXISTS = 5;
    public const DDL_CACHE_PREFIX = DdlCache::PREFIX;
    public const DDL_CACHE_TAG = DdlCache::TAG;

    private int $statementSavepoint = 0;

    private bool $syncingIdentity = false;

    private int $transactionLevel = 0;

    private bool $isRolledBack = false;

    private ?SchemaListener $schemaListener = null;

    private ?QueryGenerator $queryGenerator = null;

    public function __construct(
        private readonly StringUtils $string,
        private readonly LoggerInterface $logger,
        private readonly SelectFactory $selectFactory,
        private readonly ConnectionFactory $connectionFactory,
        private readonly ExceptionMapper $exceptionMapper,
        private readonly IdentitySynchronizer $identitySynchronizer,
        private readonly BindCoercer $bindCoercer,
        private readonly SchemaIntrospector $schemaIntrospector,
        private readonly ColumnDefinitionBuilder $columnBuilder,
        private readonly UpsertBuilder $upsertBuilder,
        private readonly NameResolver $names,
        private readonly TypeMapper $types,
        private readonly \Psr\Log\LoggerInterface $sqlLogger,
        private readonly QueryRewriter $queryRewriter,
        private readonly SqlExpressions $sqlExpressions,
        private readonly CompatBootstrap $compatBootstrap,
        private readonly DdlCache $ddlCache,
        private readonly TableSql $tableSql,
        private readonly SelectWriteSql $selectWriteSql,
        array $config = []
    ) {
        try {
            parent::__construct($config);
        } catch (\Zend_Db_Adapter_Exception $e) {
            throw new \InvalidArgumentException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    // -----------------------------------------------------------------
    // Connection / quoting
    // -----------------------------------------------------------------

    protected function _connect()
    {
        if ($this->_connection) {
            return;
        }
        $this->_config = $this->connectionFactory->normalize($this->_config);
        $this->logger->startTimer();
        $this->_connection = $this->connectionFactory->connect($this->_config);
        $this->logger->logStats(LoggerInterface::TYPE_CONNECT, '');
        $this->compatBootstrap->markDirty();
        $this->compatBootstrap->ensure($this->_connection, fn () => $this->resetDdlCache('catalog_product_entity'));
    }

    protected function _dsn()
    {
        return $this->connectionFactory->dsn($this->_config);
    }

    public function quoteIdentifier($ident, $auto = false)
    {
        if (is_string($ident) && preg_match('/\s|\(|CASE\s+WHEN|COALESCE/i', $ident)) {
            return $ident;
        }
        return parent::quoteIdentifier($ident, $auto);
    }

    /**
     * Int/float scalars are always quoted as string literals (e.g. '5', not 5).
     * Unlike MySQL, Postgres has no implicit string<->numeric coercion in binary
     * operators; quoting a scalar as an "unknown"-typed literal lets Postgres infer
     * its actual type from context (the compared column) instead, matching the
     * leniency core's generated SQL assumes.
     */
    protected function _quote($value)
    {
        if (is_int($value) || is_float($value)) {
            return PgIdentifier::quoteValue((string) $value);
        }
        return parent::_quote($value);
    }

    public function quoteInto($text, $value, $type = null, $count = null)
    {
        if (is_array($value) && empty($value)) {
            $value = new \Zend_Db_Expr('NULL');
        } else {
            $value = $this->sqlExpressions->coerceQuoteIntoValue((string) $text, $value);
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        return parent::quoteInto($text, $value, $type, $count);
    }

    public function select()
    {
        return $this->selectFactory->create($this);
    }

    // -----------------------------------------------------------------
    // Query pipeline: savepoints, exception mapping, identity sync - collapsed into
    // one override since Zend_Db_Adapter_Abstract doesn't split query() into
    // _prepareQuery()/_query() hooks the way Magento's own Mysql adapter does.
    //
    // Deliberately does not run any general-purpose SQL-text rewrite: core's
    // MySQL-shaped SQL is fixed at the source. QueryRewriter holds the two
    // remaining exceptions (SHOW TABLES/TRIGGERS, ON DUPLICATE KEY).
    // -----------------------------------------------------------------

    public function query($sql, $bind = [])
    {
        $this->_connect();
        $sqlString = $sql instanceof Select ? $sql->__toString() : (string) $sql;
        if (!$this->compatBootstrap->isReady()
            && !preg_match('/^\s*(DROP|CREATE)\s+SCHEMA\b/i', $sqlString)
        ) {
            $this->compatBootstrap->ensure($this->_connection, fn () => $this->resetDdlCache('catalog_product_entity'));
        }
        $sqlString = $this->queryRewriter->rewrite($sqlString, $this);

        $useSavepoint = !$this->syncingIdentity
            && $this->transactionLevel > 0
            && !preg_match('/^\s*(SAVEPOINT|RELEASE\s+SAVEPOINT|ROLLBACK|COMMIT|BEGIN|START\s+TRANSACTION)/i', $sqlString);
        $sp = null;
        if ($useSavepoint) {
            $sp = 'magento_q' . (++$this->statementSavepoint);
            $this->_connection->exec('SAVEPOINT ' . $sp);
        }
        $result = null;
        try {
            $this->logger->startTimer();
            $result = parent::query($sqlString, $bind);
            if ($sp) {
                $this->_connection->exec('RELEASE SAVEPOINT ' . $sp);
            }
            $this->syncIdentityAfterSql($sqlString);
            $this->copyIndexesAfterTableLike($sqlString);
            if (stripos($sqlString, 'DROP SCHEMA') !== false) {
                $this->compatBootstrap->markDirty();
            }
            return $result;
        } catch (\Exception $e) {
            if ($sp) {
                try {
                    $this->_connection->exec('ROLLBACK TO SAVEPOINT ' . $sp);
                } catch (\Exception $ignored) {
                }
            }
            $mapped = $this->exceptionMapper->map($e);
            $this->logQueryFailure($sqlString, $mapped);
            throw $mapped;
        } finally {
            $this->logger->logStats(LoggerInterface::TYPE_QUERY, $sqlString, $bind, $result);
        }
    }

    /**
     * Deprecated alias for query() on Magento's Mysql adapter. Core still calls it
     * (e.g. CatalogRule IndexerTableSwapper restoring triggers after a rename).
     */
    public function multiQuery($sql, $bind = [])
    {
        return $this->query($sql, $bind);
    }

    private function logQueryFailure(string $sql, \Exception $mapped): void
    {
        $expected = $mapped instanceof DeadlockException
            || $mapped instanceof LockWaitException
            || $mapped instanceof DuplicateException
            || $mapped instanceof TableNotFoundException
            || $mapped instanceof ConnectionException;
        $context = ['sql' => $sql, 'exception' => $mapped->getMessage()];
        if ($expected) {
            $this->sqlLogger->debug('Postgres query failed (expected/retryable)', $context);
        } else {
            $this->sqlLogger->error('Postgres query failed', $context);
        }
    }

    private function syncIdentityAfterSql(string $sql): void
    {
        if ($this->syncingIdentity) {
            return;
        }
        $this->syncingIdentity = true;
        try {
            $this->identitySynchronizer->syncAfterInsert($this->_connection, $this, $sql);
        } finally {
            $this->syncingIdentity = false;
        }
    }

    public function lastInsertId($tableName = null, $primaryKey = null)
    {
        $this->_connect();
        return $this->identitySynchronizer->resolveLastInsertId($this->_connection, $this, $tableName, $primaryKey);
    }

    private function copyIndexesAfterTableLike(string $sql): void
    {
        if (stripos($sql, 'TEMPORARY') !== false) {
            return;
        }
        if (!preg_match(
            '/^\s*CREATE\s+TABLE\s+"?([A-Za-z0-9_]+)"?\s+\(LIKE\s+"?([A-Za-z0-9_]+)"?/i',
            $sql,
            $m
        )) {
            return;
        }
        $this->copyTableIndexesAndConstraints($m[2], $m[1]);
    }

    private function copyTableIndexesAndConstraints(string $from, string $to): void
    {
        $toIdent = $this->quoteIdentifier($to);
        $this->resetDdlCache($from);
        foreach ($this->getIndexList($from) as $index) {
            $type = $index['INDEX_TYPE'] ?? '';
            $cols = $index['COLUMNS_LIST'] ?? [];
            if (!$cols) {
                continue;
            }
            $quotedCols = implode(',', array_map([$this, 'quoteIdentifier'], $cols));
            if ($type === 'primary') {
                $this->_connection->exec(sprintf('ALTER TABLE %s ADD PRIMARY KEY (%s)', $toIdent, $quotedCols));
                continue;
            }
            $name = $this->quoteIdentifier($this->names->relationName($index['KEY_NAME'], $to));
            if ($type === 'unique') {
                $this->_connection->exec(sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (%s)',
                    $toIdent,
                    $name,
                    $quotedCols
                ));
                continue;
            }
            $this->_connection->exec(sprintf('CREATE INDEX %s ON %s (%s)', $name, $toIdent, $quotedCols));
        }
        $this->resetDdlCache($to);
    }

    // -----------------------------------------------------------------
    // Transactions - nesting counter is generic Magento behavior layered on top of a
    // single real DB transaction (Zend_Db_Adapter_Abstract's own begin/commit/rollback),
    // no MySQL-specific SQL involved; ported directly. Per-statement SAVEPOINTs in
    // query() above are a separate, unrelated mechanism (surviving one bad statement
    // inside an open transaction).
    // -----------------------------------------------------------------

    public function beginTransaction()
    {
        if ($this->isRolledBack) {
            throw new \Exception(AdapterInterface::ERROR_ROLLBACK_INCOMPLETE_MESSAGE);
        }
        if ($this->transactionLevel === 0) {
            $this->logger->startTimer();
            try {
                $this->_connect();
                parent::beginTransaction();
            } finally {
                $this->logger->logStats(LoggerInterface::TYPE_TRANSACTION, 'BEGIN');
            }
        }
        ++$this->transactionLevel;
        return $this;
    }

    public function commit()
    {
        if ($this->transactionLevel === 1 && !$this->isRolledBack) {
            $this->logger->startTimer();
            parent::commit();
            $this->logger->logStats(LoggerInterface::TYPE_TRANSACTION, 'COMMIT');
        } elseif ($this->transactionLevel === 0) {
            throw new \Exception(AdapterInterface::ERROR_ASYMMETRIC_COMMIT_MESSAGE);
        } elseif ($this->isRolledBack) {
            throw new \Exception(AdapterInterface::ERROR_ROLLBACK_INCOMPLETE_MESSAGE);
        }
        --$this->transactionLevel;
        return $this;
    }

    public function rollBack()
    {
        if ($this->transactionLevel === 1) {
            $this->logger->startTimer();
            parent::rollBack();
            $this->isRolledBack = false;
            $this->logger->logStats(LoggerInterface::TYPE_TRANSACTION, 'ROLLBACK');
        } elseif ($this->transactionLevel === 0) {
            throw new \Exception(AdapterInterface::ERROR_ASYMMETRIC_ROLLBACK_MESSAGE);
        } else {
            $this->isRolledBack = true;
        }
        --$this->transactionLevel;
        return $this;
    }

    public function getTransactionLevel()
    {
        return $this->transactionLevel;
    }

    // -----------------------------------------------------------------
    // Schema introspection - delegates to SchemaIntrospector, wrapped in the DDL
    // cache contract AdapterInterface expects.
    // -----------------------------------------------------------------

    public function isTableExists($tableName, $schemaName = null)
    {
        return $this->schemaIntrospector->tableExists($this, $tableName, $schemaName ?: 'public');
    }

    public function describeTable($tableName, $schemaName = null)
    {
        $schemaName = $schemaName ?: 'public';
        $cacheKey = $this->_getTableName($tableName, $schemaName);
        $ddl = $this->loadDdlCache($cacheKey, self::DDL_DESCRIBE);
        if ($ddl !== false) {
            return $ddl;
        }
        $ddl = $this->schemaIntrospector->describeColumns($this, $tableName, $schemaName);
        $this->saveDdlCache($cacheKey, self::DDL_DESCRIBE, $ddl);
        return $ddl;
    }

    public function getIndexList($tableName, $schemaName = null)
    {
        $schemaName = $schemaName ?: 'public';
        $cacheKey = $this->_getTableName($tableName, $schemaName);
        $ddl = $this->loadDdlCache($cacheKey, self::DDL_INDEX);
        if ($ddl !== false) {
            return $ddl;
        }
        $ddl = $this->schemaIntrospector->indexList($this, $tableName, $schemaName);
        $this->saveDdlCache($cacheKey, self::DDL_INDEX, $ddl);
        return $ddl;
    }

    public function getForeignKeys($tableName, $schemaName = null)
    {
        $schemaName = $schemaName ?: 'public';
        $cacheKey = $this->_getTableName($tableName, $schemaName);
        $ddl = $this->loadDdlCache($cacheKey, self::DDL_FOREIGN_KEY);
        if ($ddl !== false) {
            return $ddl;
        }
        $ddl = $this->schemaIntrospector->foreignKeys($this, $tableName, $schemaName);
        $this->saveDdlCache($cacheKey, self::DDL_FOREIGN_KEY, $ddl);
        return $ddl;
    }

    public function tableColumnExists($tableName, $columnName, $schemaName = null)
    {
        foreach ($this->describeTable($tableName, $schemaName) as $column) {
            if ($column['COLUMN_NAME'] == $columnName) {
                return true;
            }
        }
        return false;
    }

    public function getTables($likeCondition = null)
    {
        $sql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public'";
        if ($likeCondition !== null) {
            $sql .= ' AND tablename LIKE ' . $this->quote($likeCondition);
        }
        return $this->fetchCol($sql);
    }

    public function showTableStatus($tableName, $schemaName = null)
    {
        $name = $this->_getTableName($tableName, $schemaName);
        $autoIncrement = 1;
        try {
            $identity = $this->getAutoIncrementField($name);
            if ($identity) {
                $seq = $this->fetchOne(
                    'SELECT pg_get_serial_sequence(' . $this->quote($name) . ', ' . $this->quote($identity) . ')'
                );
                if ($seq) {
                    $row = $this->fetchRow('SELECT last_value, is_called FROM ' . $seq);
                    $autoIncrement = !empty($row['is_called'])
                        ? (int) $row['last_value'] + 1
                        : (int) $row['last_value'];
                }
            }
        } catch (\Exception $e) {
        }
        return [
            'Name' => $name,
            'Engine' => 'innodb',
            'Rows' => 0,
            'Auto_increment' => $autoIncrement,
            'Comment' => '',
        ];
    }

    public function getPrimaryKeyName($tableName, $schemaName = null)
    {
        $indexes = $this->getIndexList($tableName, $schemaName);
        foreach ($indexes as $index) {
            if (($index['INDEX_TYPE'] ?? null) === AdapterInterface::INDEX_TYPE_PRIMARY) {
                return $index['KEY_NAME'];
            }
        }
        return 'PK_' . strtoupper((string) $tableName);
    }

    public function getAutoIncrementField($tableName, $schemaName = null)
    {
        $indexName = $this->getPrimaryKeyName($tableName, $schemaName);
        $indexes = $this->getIndexList($tableName, $schemaName);
        if ($indexName && isset($indexes[$indexName]) && count($indexes[$indexName]['COLUMNS_LIST']) === 1) {
            return current($indexes[$indexName]['COLUMNS_LIST']);
        }
        return false;
    }

    /**
     * MySQL's CHECKSUM TABLE has no Postgres equivalent - hashes the concatenated row
     * data instead, which serves the same "did this table's content change" purpose.
     */
    public function getTablesChecksum($tableNames, $schemaName = null)
    {
        $result = [];
        $tableNames = is_array($tableNames) ? $tableNames : [$tableNames];
        foreach ($tableNames as $tableName) {
            $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
            $result[$tableName] = $this->fetchOne(
                sprintf('SELECT md5(CAST((array_agg(t.*)) AS text)) FROM %s AS t', $table)
            );
        }
        return $result;
    }

    public function decodeVarbinary($value)
    {
        return $value;
    }

    // -----------------------------------------------------------------
    // DDL cache - generic (serializer + optional cache frontend), no MySQL-specific
    // logic; ported from Magento\Framework\DB\Adapter\Pdo\Mysql almost verbatim.
    // -----------------------------------------------------------------

    protected function _getTableName($tableName, $schemaName = null)
    {
        return ($schemaName ? $schemaName . '.' : '') . $tableName;
    }

    public function loadDdlCache($tableCacheKey, $ddlType)
    {
        return $this->ddlCache->load((string) $tableCacheKey, (int) $ddlType);
    }

    public function saveDdlCache($tableCacheKey, $ddlType, $data)
    {
        $this->ddlCache->save((string) $tableCacheKey, (int) $ddlType, $data);
        return $this;
    }

    /**
     * Live-verified bug: describeTable()/getIndexList()/getForeignKeys() all normalize
     * a null $schemaName to 'public' before computing their cache key, but callers of
     * resetDdlCache() pass $schemaName through as-is (almost always null). Without the
     * same normalization, the key this method cleared never matched "public.tablename".
     */
    public function resetDdlCache($tableName = null, $schemaName = null)
    {
        $this->ddlCache->reset(
            $tableName,
            $schemaName,
            [self::DDL_DESCRIBE, self::DDL_CREATE, self::DDL_INDEX, self::DDL_FOREIGN_KEY, self::DDL_EXISTS]
        );
        return $this;
    }

    public function allowDdlCache()
    {
        $this->ddlCache->allow();
        return $this;
    }

    public function disallowDdlCache()
    {
        $this->ddlCache->disallow();
        return $this;
    }

    public function setCacheAdapter(FrontendInterface $cacheAdapter)
    {
        $this->ddlCache->setCacheAdapter($cacheAdapter);
        return $this;
    }

    public function getSchemaListener(): SchemaListener
    {
        if ($this->schemaListener === null) {
            $this->schemaListener = \Magento\Framework\App\ObjectManager::getInstance()->create(SchemaListener::class);
        }
        return $this->schemaListener;
    }

    private function getQueryGenerator(): QueryGenerator
    {
        if ($this->queryGenerator === null) {
            $this->queryGenerator = \Magento\Framework\App\ObjectManager::getInstance()->create(QueryGenerator::class);
        }
        return $this->queryGenerator;
    }

    // -----------------------------------------------------------------
    // DDL: tables - native Postgres generation from Table/Column DTOs via
    // ColumnDefinitionBuilder/IndexDefinitionBuilder.
    // -----------------------------------------------------------------

    public function newTable($tableName = null, $schemaName = null)
    {
        $table = new Table();
        if ($tableName !== null) {
            $table->setName($tableName);
        }
        if ($schemaName !== null) {
            $table->setSchema($schemaName);
        }
        return $table;
    }

    public function createTable(Table $table)
    {
        $this->getSchemaListener()->createTable($table);
        $built = $this->tableSql->createTable($table);
        $result = $this->query($built['sql']);
        foreach ($built['deferred'] as $indexSql) {
            $this->query($indexSql);
        }
        $this->resetDdlCache($table->getName(), $table->getSchema());
        return $result;
    }

    public function createTemporaryTable(Table $table)
    {
        return $this->query($this->tableSql->createTemporaryTable($table));
    }

    public function createTemporaryTableLike($temporaryTableName, $originTableName, $ifNotExists = false)
    {
        $sql = sprintf(
            'CREATE TEMPORARY TABLE%s %s (LIKE %s INCLUDING ALL)',
            $ifNotExists ? ' IF NOT EXISTS' : '',
            $this->quoteIdentifier($this->_getTableName($temporaryTableName)),
            $this->quoteIdentifier($this->_getTableName($originTableName))
        );
        return $this->query($sql);
    }

    /**
     * Non-temporary counterpart to createTemporaryTableLike() for Magento indexers
     * that still emit MySQL `CREATE TABLE x LIKE y` (CatalogRule IndexerTableSwapper).
     */
    public function createTableLike($newTableName, $originTableName)
    {
        // Deliberately not INCLUDING ALL: INCLUDING ALL pulls in INDEXES too, which
        // copies the origin table's PRIMARY KEY/UNIQUE constraints onto the new table by
        // itself - copyIndexesAfterTableLike()/copyTableIndexesAndConstraints() below
        // (triggered by query() for this exact "CREATE TABLE ... (LIKE ...)" shape) then
        // tries to add the same PRIMARY KEY a second time and Postgres rejects it
        // ("multiple primary keys ... are not allowed"). Everything else INCLUDING ALL
        // would copy (defaults, generated/identity columns, comments, storage) is safe
        // to keep since nothing else re-derives it.
        return $this->query(sprintf(
            'CREATE TABLE %s (LIKE %s INCLUDING DEFAULTS INCLUDING GENERATED INCLUDING IDENTITY'
                . ' INCLUDING COMMENTS INCLUDING STORAGE)',
            $this->quoteIdentifier($newTableName),
            $this->quoteIdentifier($originTableName)
        ));
    }

    public function dropTemporaryTable($tableName, $schemaName = null)
    {
        $this->query('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)));
        return true;
    }

    public function dropTable($tableName, $schemaName = null)
    {
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $this->query('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
        $this->resetDdlCache($tableName, $schemaName);
        $this->getSchemaListener()->dropTable($tableName);
        return true;
    }

    public function truncateTable($tableName, $schemaName = null)
    {
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $this->query('TRUNCATE TABLE ' . $table . ' RESTART IDENTITY CASCADE');
        return $this;
    }

    public function renameTable($oldTableName, $newTableName, $schemaName = null)
    {
        return $this->renameTablesBatch([['oldName' => $oldTableName, 'newName' => $newTableName]]);
    }

    public function renameTablesBatch(array $tablePairs)
    {
        if (count($tablePairs) === 0) {
            throw new \Zend_Db_Exception('Please provide tables for rename');
        }
        foreach ($tablePairs as $pair) {
            $this->query(sprintf(
                'ALTER TABLE %s RENAME TO %s',
                $this->quoteIdentifier($pair['oldName']),
                $this->quoteIdentifier($pair['newName'])
            ));
            $this->resetDdlCache($pair['oldName']);
            $this->resetDdlCache($pair['newName']);
        }
        return true;
    }

    /**
     * Mysql adapter's SHOW CREATE TABLE wrapper. Core (Mview changelog, Installer)
     * only inspects the text for a utf8mb4 charset marker; Postgres has no per-table
     * charset, so an empty string makes those checks no-ops.
     */
    public function getCreateTable($tableName, $schemaName = null)
    {
        return '';
    }

    public function createTableByDdl($tableName, $newTableName)
    {
        $describe = $this->describeTable($tableName);
        $table = $this->newTable($newTableName)->setComment($this->string->upperCaseWords($newTableName, '_', ' '));
        foreach ($describe as $columnData) {
            // describeTable() already ran DATA_TYPE through TypeMapper::toMagentoType() -
            // it's the raw MySQL-DESCRIBE-style name ('int', 'varchar', ...), not a
            // Postgres catalog type, so re-running toMagentoType() on it here would be a
            // redundant (if coincidentally harmless) second translation. What it does
            // need is ddlTypeForRawType(), since Table::addColumn() requires an actual
            // Table::TYPE_* constant and several of those raw names aren't one (see that
            // method's own docblock).
            $magentoType = $this->types->ddlTypeForRawType($columnData['DATA_TYPE']);
            $table->addColumn(
                $columnData['COLUMN_NAME'],
                $magentoType,
                $columnData['LENGTH'],
                ['nullable' => $columnData['NULLABLE'], 'default' => $columnData['DEFAULT']],
                $columnData['COLUMN_NAME']
            );
        }
        foreach ($this->getIndexList($tableName) as $indexData) {
            if ($indexData['INDEX_TYPE'] === AdapterInterface::INDEX_TYPE_PRIMARY) {
                continue;
            }
            $table->addIndex($indexData['KEY_NAME'], $indexData['COLUMNS_LIST'], ['type' => $indexData['INDEX_TYPE']]);
        }
        return $table;
    }

    // -----------------------------------------------------------------
    // DDL: columns
    // -----------------------------------------------------------------

    public function addColumn($tableName, $columnName, $definition, $schemaName = null)
    {
        $this->getSchemaListener()->addColumn($tableName, $columnName, $definition);
        if ($this->tableColumnExists($tableName, $columnName, $schemaName)) {
            return true;
        }
        $primaryKeySql = '';
        if (is_array($definition)) {
            $definition = array_change_key_case($definition, CASE_UPPER);
            if (empty($definition['COMMENT'])) {
                throw new \Zend_Db_Exception('Impossible to create a column without comment.');
            }
            if (!empty($definition['PRIMARY'])) {
                $primaryKeySql = sprintf(', ADD PRIMARY KEY (%s)', $this->quoteIdentifier($columnName));
            }
            $definition = $this->tableSql->columnDefinition($definition);
        }
        $result = $this->query(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s%s',
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
            $this->quoteIdentifier($columnName),
            $definition,
            $primaryKeySql
        ));
        $this->resetDdlCache($tableName, $schemaName);
        return $result;
    }

    public function dropColumn($tableName, $columnName, $schemaName = null)
    {
        if (!$this->tableColumnExists($tableName, $columnName, $schemaName)) {
            return true;
        }
        $this->getSchemaListener()->dropColumn($tableName, $columnName);
        $alterDrop = [];
        foreach ($this->getForeignKeys($tableName, $schemaName) as $fkProp) {
            if ($fkProp['COLUMN_NAME'] == $columnName) {
                $this->getSchemaListener()->dropForeignKey($tableName, $fkProp['FK_NAME']);
                $alterDrop[] = 'DROP CONSTRAINT ' . $this->quoteIdentifier($fkProp['FK_NAME']);
            }
        }
        foreach ($this->getIndexList($tableName, $schemaName) as $idxData) {
            $idxColumns = $idxData['COLUMNS_LIST'];
            $idxColumnKey = array_search($columnName, $idxColumns);
            if ($idxColumnKey !== false && count($idxColumns) === 1) {
                $this->getSchemaListener()->dropIndex($tableName, $idxData['KEY_NAME'], 'index');
            }
        }
        $alterDrop[] = 'DROP COLUMN ' . $this->quoteIdentifier($columnName);
        $result = $this->query(sprintf(
            'ALTER TABLE %s %s',
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
            implode(', ', $alterDrop)
        ));
        $this->resetDdlCache($tableName, $schemaName);
        return $result;
    }

    /**
     * Postgres has no combined MySQL-style CHANGE COLUMN statement - renaming,
     * retyping, and changing nullability/default each need their own ALTER COLUMN
     * (or ALTER TABLE ... RENAME COLUMN) clause, so this issues several separate
     * statements instead of one.
     */
    public function changeColumn(
        $tableName,
        $oldColumnName,
        $newColumnName,
        $definition,
        $flushData = false,
        $schemaName = null
    ) {
        $this->getSchemaListener()->changeColumn($tableName, $oldColumnName, $newColumnName, $definition);
        if (!$this->tableColumnExists($tableName, $oldColumnName, $schemaName)) {
            throw new \Zend_Db_Exception(sprintf(
                'Column "%s" does not exist in table "%s".',
                $oldColumnName,
                $tableName
            ));
        }
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));

        if ($oldColumnName !== $newColumnName) {
            $this->query(sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $table,
                $this->quoteIdentifier($oldColumnName),
                $this->quoteIdentifier($newColumnName)
            ));
        }

        $result = is_array($definition)
            ? $this->changeColumnAttributes($table, $newColumnName, $definition)
            : $this->query(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE %s',
                $table,
                $this->quoteIdentifier($newColumnName),
                $this->translateRawTypeString($definition)
            ));

        if ($flushData) {
            $this->showTableStatus($tableName, $schemaName);
        }
        $this->resetDdlCache($tableName, $schemaName);
        return $result;
    }

    private function changeColumnAttributes(string $quotedTable, string $columnName, array $options)
    {
        $options = array_change_key_case($options, CASE_UPPER);
        $ddlType = $options['TYPE'] ?? ($options['COLUMN_TYPE'] ?? null);
        $pgType = $this->types->toPostgres($ddlType, $options);
        $quotedColumn = $this->quoteIdentifier($columnName);

        $this->query(sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s', $quotedTable, $quotedColumn, $pgType));

        $nullable = array_key_exists('NULLABLE', $options) ? (bool) $options['NULLABLE'] : true;
        $this->query(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s %s',
            $quotedTable,
            $quotedColumn,
            $nullable ? 'DROP NOT NULL' : 'SET NOT NULL'
        ));

        if (!empty($options['IDENTITY']) || !empty($options['AUTO_INCREMENT'])) {
            return $this->query(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s ADD GENERATED BY DEFAULT AS IDENTITY',
                $quotedTable,
                $quotedColumn
            ));
        }

        $hasDefault = array_key_exists('DEFAULT', $options) && $options['DEFAULT'] !== false;
        if ($hasDefault) {
            $defaultClause = ltrim($this->columnBuilder->explicitDefault($options['DEFAULT']));
        } elseif (!$nullable) {
            $defaultClause = ltrim($this->columnBuilder->implicitNotNullDefault($pgType));
        } else {
            $defaultClause = '';
        }
        if ($defaultClause === '') {
            return $this->query(sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $quotedTable, $quotedColumn));
        }
        return $this->query(sprintf('ALTER TABLE %s ALTER COLUMN %s SET %s', $quotedTable, $quotedColumn, $defaultClause));
    }

    public function modifyColumn($tableName, $columnName, $definition, $flushData = false, $schemaName = null)
    {
        $this->getSchemaListener()->modifyColumn($tableName, $columnName, $definition);
        if (!$this->tableColumnExists($tableName, $columnName, $schemaName)) {
            throw new \Zend_Db_Exception(sprintf('Column "%s" does not exist in table "%s".', $columnName, $tableName));
        }
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $result = is_array($definition)
            ? $this->changeColumnAttributes($table, $columnName, $definition)
            : $this->query(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE %s',
                $table,
                $this->quoteIdentifier($columnName),
                $this->translateRawTypeString($definition)
            ));
        if ($flushData) {
            $this->showTableStatus($tableName, $schemaName);
        }
        $this->resetDdlCache($tableName, $schemaName);
        return $result;
    }

    /**
     * Translates a bare MySQL type keyword (e.g. 'mediumtext', 'INT(10) UNSIGNED') to its
     * Postgres equivalent, for the string-$definition overload of changeColumn()/
     * modifyColumn() (AdapterInterface's legacy, non-declarative-schema DDL API - still
     * called directly by a handful of core/setup callers, e.g. setup/src/Magento/Setup/
     * Model/Installer.php's flag_data mediumtext upgrade fixup). Reuses TypeMapper's own
     * keyword-replacement table rather than duplicating it - same source the declarative-
     * schema MODIFY COLUMN path (MysqlToPostgres::translateFragment()) draws from.
     */
    private function translateRawTypeString(string $definition): string
    {
        foreach ($this->types->keywordReplacements() as [$pattern, $replacement]) {
            $definition = preg_replace($pattern, $replacement, $definition);
        }
        return $definition;
    }

    public function modifyColumnByDdl($tableName, $columnName, $definition, $flushData = false, $schemaName = null)
    {
        $definition = array_change_key_case($definition, CASE_UPPER);
        if (array_key_exists('DEFAULT', $definition) && $definition['DEFAULT'] === null) {
            unset($definition['DEFAULT']);
        }
        return $this->modifyColumn($tableName, $columnName, $definition, $flushData, $schemaName);
    }

    // -----------------------------------------------------------------
    // DDL: indexes / foreign keys
    // -----------------------------------------------------------------

    public function addIndex(
        $tableName,
        $indexName,
        $fields,
        $indexType = AdapterInterface::INDEX_TYPE_INDEX,
        $schemaName = null
    ) {
        $this->getSchemaListener()->addIndex($tableName, $indexName, $fields, $indexType);
        $columns = $this->describeTable($tableName, $schemaName);
        if (!is_array($fields)) {
            $fields = [$fields];
        }
        $fieldSql = [];
        foreach ($fields as $field) {
            if (!isset($columns[$field])) {
                throw new \Zend_Db_Exception(sprintf(
                    'There is no field "%s" that you are trying to create an index on "%s"',
                    $field,
                    $tableName
                ));
            }
            $fieldSql[] = $this->quoteIdentifier($field);
        }
        $cols = implode(',', $fieldSql);
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $quotedName = $this->quoteIdentifier($indexName);
        $type = strtolower((string) $indexType);
        if ($type === AdapterInterface::INDEX_TYPE_PRIMARY) {
            $this->query(sprintf('ALTER TABLE %s ADD PRIMARY KEY (%s)', $table, $cols));
        } elseif ($type === AdapterInterface::INDEX_TYPE_FULLTEXT) {
            $this->resetDdlCache($tableName, $schemaName);
            return $this;
        } else {
            $unique = $type === AdapterInterface::INDEX_TYPE_UNIQUE ? 'UNIQUE ' : '';
            $this->query(sprintf('DROP INDEX IF EXISTS %s', $quotedName));
            $this->query(sprintf('CREATE %sINDEX %s ON %s (%s)', $unique, $quotedName, $table, $cols));
        }
        $this->resetDdlCache($tableName, $schemaName);
        return $this;
    }

    public function dropIndex($tableName, $keyName, $schemaName = null)
    {
        $this->getSchemaListener()->dropIndex($tableName, $keyName, 'index');
        $indexes = $this->getIndexList($tableName, $schemaName);
        $index = $indexes[strtoupper($keyName)] ?? $indexes[$keyName] ?? null;
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        if ($index && ($index['INDEX_TYPE'] ?? null) === AdapterInterface::INDEX_TYPE_PRIMARY) {
            $this->query(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $table, $this->quoteIdentifier($keyName)));
        } else {
            $this->query(sprintf('DROP INDEX IF EXISTS %s', $this->quoteIdentifier($keyName)));
        }
        $this->resetDdlCache($tableName, $schemaName);
        return $this;
    }

    public function getIndexName($tableName, $fields, $indexType = '')
    {
        if (is_array($fields)) {
            $fields = implode('_', $fields);
        }
        $prefix = match (strtolower((string) $indexType)) {
            AdapterInterface::INDEX_TYPE_UNIQUE => 'unq_',
            AdapterInterface::INDEX_TYPE_FULLTEXT => 'fti_',
            default => 'idx_',
        };
        return strtoupper(ExpressionConverter::shortenEntityName($tableName . '_' . $fields, $prefix));
    }

    public function addForeignKey(
        $fkName,
        $tableName,
        $columnName,
        $refTableName,
        $refColumnName,
        $onDelete = AdapterInterface::FK_ACTION_CASCADE,
        $purge = false,
        $schemaName = null,
        $refSchemaName = null
    ) {
        $this->dropForeignKey($tableName, $fkName, $schemaName);
        if ($purge) {
            $this->purgeOrphanRecords($tableName, $columnName, $refTableName, $refColumnName, $onDelete);
        }
        $result = $this->query(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
            $this->quoteIdentifier($fkName),
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($this->_getTableName($refTableName, $refSchemaName)),
            $this->quoteIdentifier($refColumnName),
            $this->tableSql->ddlAction($onDelete)
        ));
        $this->resetDdlCache($tableName, $schemaName);
        return $result;
    }

    public function dropForeignKey($tableName, $fkName, $schemaName = null)
    {
        $foreignKeys = $this->getForeignKeys($tableName, $schemaName);
        $fkName = $fkName !== null ? strtoupper((string) $fkName) : '';
        if (str_starts_with($fkName, 'FK_')) {
            $fkName = substr($fkName, 3);
        }
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        foreach ([$fkName, 'FK_' . $fkName] as $key) {
            if (!isset($foreignKeys[$key])) {
                continue;
            }
            $this->query(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
                $table,
                $this->quoteIdentifier($foreignKeys[$key]['FK_NAME'])
            ));
            $this->resetDdlCache($tableName, $schemaName);
            $this->getSchemaListener()->dropForeignKey($tableName, $fkName);
        }
        return $this;
    }

    public function getForeignKeyName($priTableName, $priColumnName, $refTableName, $refColumnName)
    {
        $fkName = sprintf('%s_%s_%s_%s', $priTableName, $priColumnName, $refTableName, $refColumnName);
        return strtoupper(ExpressionConverter::shortenEntityName($fkName, 'fk_'));
    }

    public function disableTableKeys($tableName, $schemaName = null)
    {
        return $this;
    }

    public function enableTableKeys($tableName, $schemaName = null)
    {
        return $this;
    }

    // -----------------------------------------------------------------
    // Triggers
    // -----------------------------------------------------------------

    public function getTriggerName($tableName, $time, $event)
    {
        return ExpressionConverter::shortenEntityName('trg_' . $tableName . '_' . $time . '_' . $event, 'trg_');
    }

    public function createTrigger(Trigger $trigger)
    {
        if (!$trigger->getStatements()) {
            throw new \Zend_Db_Exception('Trigger ' . $trigger->getName() . ' has not statements available');
        }
        // PgCompat: no general SQL-text rewrite here (see query()'s docblock) - only
        // these trigger-body substitutions (INSERT IGNORE, SET NEW., <=>) are applied.
        $statements = implode("\n", $trigger->getStatements());
        $statements = preg_replace('/\bINSERT IGNORE\b/i', 'INSERT', $statements);
        $statements = preg_replace('/\bSET\s+NEW\./i', 'NEW.', $statements);
        $statements = str_replace('<=>', ' IS NOT DISTINCT FROM ', $statements);
        $return = strtoupper($trigger->getEvent()) === 'DELETE' ? 'OLD' : 'NEW';
        $fn = 'fn_' . preg_replace('/[^A-Za-z0-9_]/', '_', $trigger->getName());
        $this->query(sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger AS \$\$\nBEGIN\n%s\nRETURN %s;\nEND;\n\$\$ LANGUAGE plpgsql",
            $this->quoteIdentifier($fn),
            $statements,
            $return
        ));
        $this->dropTrigger($trigger->getName());
        return $this->query(sprintf(
            'CREATE TRIGGER %s %s %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
            $this->quoteIdentifier($trigger->getName()),
            $trigger->getTime(),
            $trigger->getEvent(),
            $this->quoteIdentifier($trigger->getTable()),
            $this->quoteIdentifier($fn)
        ));
    }

    public function dropTrigger($triggerName, $schemaName = null)
    {
        if (empty($triggerName)) {
            throw new \InvalidArgumentException('Trigger name is not defined');
        }
        $rows = $this->fetchAll(
            'SELECT c.relname AS table_name FROM pg_trigger t
             JOIN pg_class c ON c.oid = t.tgrelid
             WHERE t.tgname = ' . $this->quote($triggerName) . ' AND NOT t.tgisinternal'
        );
        foreach ($rows as $row) {
            $this->query(sprintf(
                'DROP TRIGGER IF EXISTS %s ON %s',
                $this->quoteIdentifier($triggerName),
                $this->quoteIdentifier($row['table_name'])
            ));
        }
        return true;
    }

    // -----------------------------------------------------------------
    // Insert / update / delete
    // -----------------------------------------------------------------

    private function bareTableName($table): string
    {
        return is_string($table) ? trim($table, '"') : (string) $table;
    }

    public function insert($table, array $bind)
    {
        $describe = $this->describeTable($this->bareTableName($table));
        $bind = $this->bindCoercer->coerce($describe, $bind);
        $bind = $this->bindCoercer->omitNullIdentity($describe, $bind);
        return parent::insert($table, $bind);
    }

    public function update($table, array $bind, $where = '')
    {
        return parent::update(
            $table,
            $this->bindCoercer->coerce($this->describeTable($this->bareTableName($table)), $bind),
            $where
        );
    }

    private function prepareInsertData($row, array &$bind): string
    {
        $row = (array) $row;
        $line = [];
        foreach ($row as $value) {
            if ($value instanceof \Zend_Db_Expr) {
                $line[] = $value->__toString();
            } else {
                $line[] = '?';
                $bind[] = $value;
            }
        }
        return implode(', ', $line);
    }

    public function insertMultiple($table, array $data)
    {
        $row = reset($data);
        if (!is_array($row)) {
            return $this->insert($table, $data);
        }
        $result = 0;
        foreach ($data as $row) {
            $result += $this->insert($table, $row);
        }
        return $result;
    }

    public function insertArray($table, array $columns, array $data)
    {
        // A single-column call (e.g. MysqlMq\Setup\Recurring::install()'s
        // insertArray($table, ['name'], $queueNames)) passes $data as a flat array of
        // bare scalars, not one sub-array per row - array_combine($columns, $row) would
        // throw ("must be of type array, string given") without the (array) cast below.
        // Mirrors prepareInsertData()'s identical (array) $row handling for the same
        // single-vs-multi-column ambiguity.
        $result = 0;
        foreach ($data as $row) {
            $result += $this->insert($table, array_combine($columns, (array) $row));
        }
        return $result;
    }

    public function insertForce($table, array $bind)
    {
        return $this->insert($table, $bind);
    }

    /**
     * MySQL's ON DUPLICATE KEY UPDATE, via UpsertBuilder's Postgres ON CONFLICT
     * generation.
     */
    public function insertOnDuplicate($table, array $data, array $fields = [])
    {
        $row = reset($data);
        $bind = [];
        $values = [];
        if (is_array($row)) {
            $cols = array_keys($row);
            foreach ($data as $dataRow) {
                $values[] = '(' . $this->prepareInsertData($dataRow, $bind) . ')';
            }
        } else {
            $cols = array_keys($data);
            $values[] = '(' . $this->prepareInsertData($data, $bind) . ')';
        }
        if (empty($fields)) {
            $fields = $cols;
        }
        $insertSql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quoteIdentifier($table),
            implode(',', array_map([$this, 'quoteIdentifier'], $cols)),
            implode(', ', $values)
        );
        $conflict = $this->upsertBuilder->conflictTarget($this, $table, $cols);
        if ($conflict) {
            $conflictSet = array_map('strtolower', $conflict);
            $updates = [];
            foreach ($fields as $k => $v) {
                $field = is_string($k) ? $k : $v;
                if (!is_string($field) || (is_int($k) && in_array(strtolower($field), $conflictSet, true))) {
                    continue;
                }
                $updates[] = $field;
            }
            $insertSql .= $this->upsertBuilder->onConflictClause($conflict, $updates);
        }
        return $this->query($insertSql, array_values($bind))->rowCount();
    }

    public function insertFromSelect(Select $select, $table, array $fields = [], $mode = false)
    {
        $countFieldsInSelect = count($select->getPart(Select::COLUMNS));
        if (empty($fields) && $countFieldsInSelect > 1) {
            $fields = array_slice(array_keys($this->describeTable($table)), 0, $countFieldsInSelect);
        }
        $query = sprintf('INSERT INTO %s', $this->quoteIdentifier($table));
        if ($fields) {
            $query .= sprintf(' (%s)', implode(', ', array_map([$this, 'quoteIdentifier'], $fields)));
        }
        $query .= ' ' . $select->assemble();

        if ($mode !== self::INSERT_ON_DUPLICATE && $mode !== self::INSERT_IGNORE && $mode !== self::REPLACE) {
            return $query;
        }
        $conflict = $this->upsertBuilder->conflictTarget($this, $table, $fields ?: array_keys($this->describeTable($table)));
        if (!$conflict) {
            return $query;
        }
        if ($mode === self::INSERT_IGNORE) {
            return $query . $this->upsertBuilder->onConflictClause($conflict, []);
        }
        $updateFields = $fields;
        if (!$updateFields) {
            foreach ($this->describeTable($table) as $column) {
                if (empty($column['PRIMARY'])) {
                    $updateFields[] = $column['COLUMN_NAME'];
                }
            }
        }
        return $query . $this->upsertBuilder->onConflictClause($conflict, $updateFields);
    }

    /**
     * Built directly from the Select's structured FROM/COLUMNS/WHERE parts rather
     * than assembling MySQL's UPDATE ... JOIN syntax: Postgres's UPDATE ... FROM
     * takes a plain table list plus a WHERE join condition, not a JOIN clause, so
     * each Select part maps onto its own piece of that shape instead of one
     * combined MySQL-style statement being translated as text.
     */
    public function updateFromSelect(Select $select, $table)
    {
        return $this->selectWriteSql->updateFromSelect($this, $select, $table);
    }

    /**
     * A NOT EXISTS subquery rather than MySQL's LEFT JOIN ... IS NULL anti-join -
     * portable to both the DELETE and UPDATE ... SET NULL cases below without
     * relying on Postgres's own JOIN-based DELETE/UPDATE ... USING syntax matching
     * MySQL's shape column-for-column.
     */
    public function purgeOrphanRecords(
        $tableName,
        $columnName,
        $refTableName,
        $refColumnName,
        $onDelete = AdapterInterface::FK_ACTION_CASCADE
    ) {
        $onDelete = strtoupper($onDelete);
        $table = $this->quoteIdentifier($tableName);
        $refTable = $this->quoteIdentifier($refTableName);
        $column = $this->quoteIdentifier($columnName);
        $refColumn = $this->quoteIdentifier($refColumnName);
        $notExists = sprintf('NOT EXISTS (SELECT 1 FROM %s AS r WHERE r.%s = p.%s)', $refTable, $refColumn, $column);

        if ($onDelete === AdapterInterface::FK_ACTION_CASCADE || $onDelete === AdapterInterface::FK_ACTION_RESTRICT) {
            $this->query(sprintf('DELETE FROM %s AS p WHERE %s', $table, $notExists));
        } elseif ($onDelete === AdapterInterface::FK_ACTION_SET_NULL) {
            $this->query(sprintf('UPDATE %s AS p SET %s = NULL WHERE %s', $table, $column, $notExists));
        }
        return $this;
    }

    /**
     * $table may be the real table name or one of the Select's own correlation
     * aliases (core callers pass both), so the loop below resolves which FROM part
     * it actually refers to before building the outer DELETE - which needs its own
     * explicit alias so a self-referencing subquery (WHERE ... IN (subselect on the
     * same table)) can distinguish the row being deleted from the rows being
     * scanned.
     */
    public function deleteFromSelect(Select $select, $table)
    {
        return $this->selectWriteSql->deleteFromSelect($this, $select, $table);
    }

    public function selectsByRange($rangeField, Select $select, $stepCount = 100)
    {
        $queries = [];
        foreach ($this->getQueryGenerator()->generate($rangeField, $select, $stepCount) as $query) {
            $queries[] = $query;
        }
        return $queries;
    }

    // -----------------------------------------------------------------
    // Misc SQL helpers
    // -----------------------------------------------------------------

    public function getTableName($tableName)
    {
        return ExpressionConverter::shortenEntityName($tableName, 't_');
    }

    public function getCheckSql($expression, $true, $false)
    {
        return $this->sqlExpressions->check($expression, $true, $false);
    }

    public function getIfNullSql($expression, $value = 0)
    {
        return $this->sqlExpressions->ifNull($expression, $value);
    }

    public function getCaseSql($valueName, $casesResults, $defaultValue = null)
    {
        return $this->sqlExpressions->caseSql($valueName, $casesResults, $defaultValue);
    }

    public function getConcatSql(array $data, $separator = null)
    {
        return $this->sqlExpressions->concat($this, $data, $separator);
    }

    public function getLengthSql($string)
    {
        return $this->sqlExpressions->length($string);
    }

    /**
     * MySQL GROUP_CONCAT() as string_agg().
     */
    public function getGroupConcatSql($expression, $separator = ',', $orderBy = null, $distinct = false)
    {
        return $this->sqlExpressions->groupConcat($this, $expression, $separator, $orderBy, $distinct);
    }

    /**
     * MySQL FIELD() as CASE.
     */
    public function getFieldSql($expression, array $values)
    {
        return $this->sqlExpressions->field($expression, $values);
    }

    public function castToText($expression): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr('(' . $expression . ')::text');
    }

    public function castToNumeric($expression): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr('(' . $expression . ')::numeric');
    }

    public function getLeastSql(array $data)
    {
        return $this->sqlExpressions->least($data);
    }

    public function getGreatestSql(array $data)
    {
        return $this->sqlExpressions->greatest($data);
    }

    public function getDateAddSql($date, $interval, $unit)
    {
        return $this->sqlExpressions->dateAdd($date, $interval, $unit);
    }

    public function getDateSubSql($date, $interval, $unit)
    {
        return $this->sqlExpressions->dateSub($date, $interval, $unit);
    }

    public function getDateFormatSql($date, $format)
    {
        return $this->sqlExpressions->dateFormat($this, $date, $format);
    }

    public function getDatePartSql($date)
    {
        return $this->sqlExpressions->datePart($date);
    }

    public function getSubstringSql($stringExpression, $pos, $len = null)
    {
        return $this->sqlExpressions->substring($stringExpression, $pos, $len);
    }

    public function getStandardDeviationSql($expressionField)
    {
        return $this->sqlExpressions->standardDeviation($expressionField);
    }

    public function getDateExtractSql($date, $unit)
    {
        return $this->sqlExpressions->dateExtract($date, $unit);
    }

    public function orderRand(Select $select, $field = null)
    {
        $select->order(new \Zend_Db_Expr('RANDOM()'));
        return $this;
    }

    /**
     * TemporaryTableService createFromSelect() uses MySQL ENGINE/IGNORE form.
     * Postgres is CREATE TEMPORARY TABLE ... AS SELECT (inline indexes ignored;
     * CatalogUrlRewrite callers pass an empty list).
     */
    public function createTemporaryTableFromSelect($name, array $indexStatements, Select $select)
    {
        $sql = sprintf('CREATE TEMPORARY TABLE %s AS %s', $this->quoteIdentifier($name), (string) $select);
        return $this->query($sql, $select->getBind());
    }

    public function forUpdate($sql)
    {
        return sprintf('%s FOR UPDATE', $sql);
    }

    public function supportStraightJoin()
    {
        return false;
    }

    public function formatDate($date, $includeTime = true)
    {
        return $this->sqlExpressions->formatDate($this, $date, $includeTime);
    }

    public function prepareColumnValue(array $column, $value)
    {
        return $this->sqlExpressions->prepareColumnValue($this, $column, $value);
    }

    public function prepareSqlCondition($fieldName, $condition)
    {
        return $this->sqlExpressions->prepareSqlCondition($this, $fieldName, $condition);
    }

    public function startSetup()
    {
        return $this;
    }

    public function endSetup()
    {
        return $this;
    }
}
