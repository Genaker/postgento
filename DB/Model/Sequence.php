<?php

namespace Morozov\PgCompat\DB\Model;

use Magento\Framework\App\ResourceConnection as AppResource;
use Magento\Framework\DB\Sequence\SequenceInterface;
use Magento\SalesSequence\Model\Meta;
use Morozov\PgCompat\DB\Adapter\PostgresAdapterInterface;

class Sequence implements SequenceInterface
{
    public const DEFAULT_PATTERN = "%s%'.09d%s";

    private $lastIncrementId;
    private $meta;
    private $connection;
    private $pattern;

    public function __construct(
        Meta $meta,
        AppResource $resource,
        $pattern = self::DEFAULT_PATTERN
    ) {
        $this->meta = $meta;
        $this->connection = $resource->getConnection('sales');
        $this->pattern = $pattern;
    }

    public function getCurrentValue()
    {
        if (!isset($this->lastIncrementId)) {
            return null;
        }

        return sprintf(
            $this->pattern,
            $this->meta->getActiveProfile()->getPrefix(),
            $this->calculateCurrentValue(),
            $this->meta->getActiveProfile()->getSuffix()
        );
    }

    public function getNextValue()
    {
        if ($this->connection instanceof PostgresAdapterInterface) {
            $table = $this->connection->quoteIdentifier($this->meta->getSequenceTable());
            $this->lastIncrementId = $this->connection->fetchOne(
                "INSERT INTO {$table} (sequence_value) VALUES (DEFAULT) RETURNING sequence_value"
            );
            return $this->getCurrentValue();
        }
        $this->connection->insert($this->meta->getSequenceTable(), []);
        $this->lastIncrementId = $this->connection->lastInsertId($this->meta->getSequenceTable());
        return $this->getCurrentValue();
    }

    private function calculateCurrentValue()
    {
        return ($this->lastIncrementId - $this->meta->getActiveProfile()->getStartValue())
            * $this->meta->getActiveProfile()->getStep()
            + $this->meta->getActiveProfile()->getStartValue();
    }
}
