<?php

declare(strict_types=1);

namespace Morozov\PgCompat\DB\Setup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbSchemaWriterInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\Statement;
use Magento\Framework\Setup\Declaration\Schema\Db\StatementAggregator;
use Magento\Framework\Setup\Declaration\Schema\Db\StatementFactory;
use Magento\Framework\Setup\Declaration\Schema\Dto\Column;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;
use Magento\Framework\Setup\Declaration\Schema\Dto\Index;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Reference;
use Magento\Framework\Setup\Declaration\Schema\DryRunLogger;
use Morozov\PgCompat\DB\Ddl\MysqlToPostgres;

class DbSchemaWriter implements DbSchemaWriterInterface
{
    private $statementDirectives = [
        self::ALTER_TYPE => 'ALTER TABLE %s %s',
        self::CREATE_TYPE => 'CREATE TABLE %s %s',
        self::DROP_TYPE => 'DROP TABLE IF EXISTS %s CASCADE',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StatementFactory $statementFactory,
        private readonly DryRunLogger $dryRunLogger,
        private readonly MysqlToPostgres $translator
    ) {
    }

    public function createTable($tableName, $resource, array $definition, array $options)
    {
        $tableParts = [];
        $indexSql = [];
        foreach ($definition as $fragment) {
            if ($this->translator->isIndexFragment($fragment)) {
                $createIndex = $this->translator->indexToCreateSql($tableName, $fragment);
                if ($createIndex) {
                    $indexSql[] = $createIndex;
                }
                continue;
            }
            $tableParts[] = $this->translator->translateFragment($fragment, $tableName);
        }

        $sql = sprintf("(\n%s\n)", implode(", \n", $tableParts));
        $statement = $this->statementFactory->create(
            $tableName,
            $tableName,
            self::CREATE_TYPE,
            $sql,
            $resource
        );
        foreach ($indexSql as $indexQuery) {
            $statement->addTrigger(function () use ($resource, $indexQuery) {
                $this->resourceConnection->getConnection($resource)->query($indexQuery);
            });
        }
        return $statement;
    }

    public function dropTable($tableName, $resource)
    {
        return $this->statementFactory->create(
            $tableName,
            $tableName,
            self::DROP_TYPE,
            '',
            $resource
        );
    }

    public function addElement($elementName, $resource, $tableName, $elementDefinition, $elementType)
    {
        $translated = $this->translator->translateFragment($elementDefinition, $tableName);
        if ($elementType === Index::TYPE || $this->translator->isIndexFragment($elementDefinition)) {
            // indexToCreateSql() returns null for index shapes Postgres has no
            // equivalent for (FULLTEXT) - falling back to the raw fragment here would
            // produce a nonsensical statement once execute() wraps it in ALTER TABLE,
            // since it's neither a standalone CREATE INDEX nor a valid ALTER fragment.
            $indexSql = $this->translator->indexToCreateSql($tableName, $elementDefinition)
                ?? '/* skip unsupported index: ' . $elementName . ' */';
            return $this->statementFactory->create(
                $elementName,
                $tableName,
                self::ALTER_TYPE,
                $indexSql,
                $resource,
                $elementType
            );
        }

        $syntax = $elementType === Column::TYPE ? 'ADD COLUMN %s' : 'ADD %s';
        return $this->statementFactory->create(
            $elementName,
            $tableName,
            self::ALTER_TYPE,
            sprintf($syntax, $translated),
            $resource,
            $elementType
        );
    }

    public function modifyTableOption($tableName, $resource, $optionName, $optionValue)
    {
        return $this->statementFactory->create(
            $tableName,
            $tableName,
            self::ALTER_TYPE,
            '/* skip table option ' . $optionName . ' */',
            $resource
        );
    }

    public function modifyColumn($columnName, $resource, $tableName, $columnDefinition)
    {
        $translated = $this->translator->translateFragment($columnDefinition, $tableName);
        $translated = preg_replace('/\b(numeric|decimal)\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/i', '$1($2,$3)', $translated) ?? $translated;
        if (preg_match('/^("?[A-Za-z0-9_]+"?)\s+(?:TYPE\s+)?(\S+(?:\([^)]+\))?)/', trim($translated), $m)) {
            $translated = $m[1] . ' TYPE ' . $m[2];
        }
        return $this->statementFactory->create(
            $columnName,
            $tableName,
            self::ALTER_TYPE,
            'ALTER COLUMN ' . $translated,
            $resource
        );
    }

    public function dropElement($resource, $elementName, $tableName, $type)
    {
        $adapter = $this->resourceConnection->getConnection($resource);
        $quoted = $adapter->quoteIdentifier($elementName);
        $sql = match ($type) {
            Constraint::PRIMARY_TYPE => 'DROP CONSTRAINT IF EXISTS ' . $adapter->quoteIdentifier($tableName . '_pkey'),
            Constraint::UNIQUE_TYPE, Reference::TYPE => 'DROP CONSTRAINT IF EXISTS ' . $quoted,
            Index::TYPE => 'DROP INDEX IF EXISTS ' . $quoted,
            default => 'DROP COLUMN IF EXISTS ' . $quoted,
        };
        return $this->statementFactory->create(
            $elementName,
            $tableName,
            self::ALTER_TYPE,
            $sql,
            $resource,
            $type
        );
    }

    public function resetAutoIncrement($tableName, $resource)
    {
        $adapter = $this->resourceConnection->getConnection($resource);
        $field = $adapter->getAutoIncrementField($tableName);
        $sql = 'SELECT 1';
        if ($field) {
            $sql = sprintf(
                "SELECT setval(pg_get_serial_sequence(%s, %s), COALESCE((SELECT MAX(%s) FROM %s), 1), true)",
                $adapter->quote($tableName),
                $adapter->quote($field),
                $adapter->quoteIdentifier($field),
                $adapter->quoteIdentifier($tableName)
            );
        }
        return $this->statementFactory->create(
            sprintf('RESET_AUTOINCREMENT_%s', $tableName),
            $tableName,
            self::ALTER_TYPE,
            $sql,
            $resource
        );
    }

    public function compile(StatementAggregator $statementAggregator, $dryRun)
    {
        foreach ($statementAggregator->getStatementsBank() as $statementBank) {
            if ($dryRun) {
                foreach ($statementBank as $statement) {
                    $adapter = $this->resourceConnection->getConnection($statement->getResource());
                    $this->dryRunLogger->log($this->render($statement, $adapter->quoteIdentifier($statement->getTableName())));
                }
                continue;
            }
            foreach ($statementBank as $statement) {
                $this->execute($statement);
                foreach ($statement->getTriggers() as $trigger) {
                    call_user_func($trigger);
                }
            }
        }
    }

    private function execute(Statement $statement): void
    {
        $sql = trim($statement->getStatement());
        if ($sql === '' || str_starts_with($sql, '/*')) {
            if ($statement->getType() !== self::DROP_TYPE) {
                return;
            }
        }
        $adapter = $this->resourceConnection->getConnection($statement->getResource());
        $table = $adapter->quoteIdentifier($statement->getTableName());

        if ($statement->getType() === Index::TYPE
            || str_starts_with(strtoupper($sql), 'CREATE INDEX')
            || str_starts_with(strtoupper($sql), 'SELECT SETVAL')
            || str_starts_with(strtoupper($sql), 'SELECT 1')
        ) {
            if (!str_starts_with(strtoupper($sql), 'SELECT 1')) {
                $this->queryIdempotently($adapter, $sql);
            }
            return;
        }

        $this->queryIdempotently($adapter, $this->render($statement, $table));
    }

    /**
     * Declarative schema is expected to be safely re-runnable, but unlike ADD COLUMN /
     * DROP CONSTRAINT, Postgres' ALTER TABLE ADD CONSTRAINT has no IF NOT EXISTS form
     * (an already-applied constraint has to be tolerated by catching the "already
     * exists" error instead of preventing it at the SQL level).
     */
    private function queryIdempotently($adapter, string $sql): void
    {
        try {
            $adapter->query($sql);
        } catch (\Throwable $e) {
            if (stripos($sql, 'ADD CONSTRAINT') !== false && $this->isAlreadyExists($e)) {
                return;
            }
            throw $e;
        }
    }

    private function isAlreadyExists(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException
                && in_array($current->errorInfo[0] ?? null, ['42710', '42P07'], true)
            ) {
                return true;
            }
        }
        return false;
    }

    private function render(Statement $statement, string $quotedTable): string
    {
        $directive = $this->statementDirectives[$statement->getType()] ?? '%s %s';
        return sprintf($directive, $quotedTable, $statement->getStatement());
    }
}
