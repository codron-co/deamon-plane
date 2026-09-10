<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

class MysqlWallClockShift
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * DATETIME (and datetime with precision) ignore MySQL time_zone.
     * TIMESTAMP columns must not be shifted when the session is +03:00.
     *
     * @return list<string>
     */
    public function datetimeColumns(): array
    {
        if ($this->connection->getDriverName() !== 'mysql') {
            return [];
        }

        $database = (string) $this->connection->getDatabaseName();
        $rows = $this->connection->select(
            'SELECT TABLE_NAME, COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND DATA_TYPE = ?
               AND TABLE_NAME <> ?',
            [$database, 'datetime', 'migrations'],
        );

        $columns = [];
        foreach ($rows as $row) {
            $table = (string) ($row->TABLE_NAME ?? $row->table_name ?? '');
            $column = (string) ($row->COLUMN_NAME ?? $row->column_name ?? '');
            if ($table === '' || $column === '') {
                continue;
            }
            $columns[] = $table.'.'.$column;
        }

        return $columns;
    }

    /**
     * @return int Number of columns updated
     */
    public function shiftDatetimeHours(int $hours): int
    {
        if ($hours === 0 || $this->connection->getDriverName() !== 'mysql') {
            return 0;
        }

        if ($hours < -12 || $hours > 12) {
            throw new InvalidArgumentException('Datetime shift must be between -12 and 12 hours.');
        }

        $updated = 0;
        foreach ($this->datetimeColumns() as $qualified) {
            [$table, $column] = explode('.', $qualified, 2);
            $tableSql = $this->quoteIdentifier($table);
            $columnSql = $this->quoteIdentifier($column);
            $this->connection->statement(
                "UPDATE {$tableSql} SET {$columnSql} = DATE_ADD({$columnSql}, INTERVAL {$hours} HOUR) WHERE {$columnSql} IS NOT NULL"
            );
            $updated++;
        }

        return $updated;
    }

    public function quoteIdentifier(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }

        return '`'.$name.'`';
    }
}
