<?php

namespace Tests\Unit\Support;

use App\Support\MysqlWallClockShift;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Tests\TestCase;

class MysqlWallClockShiftTest extends TestCase
{
    public function test_quote_identifier_accepts_sql_names(): void
    {
        $shift = new MysqlWallClockShift($this->createMock(ConnectionInterface::class));

        $this->assertSame('`deployments`', $shift->quoteIdentifier('deployments'));
        $this->assertSame('`started_at`', $shift->quoteIdentifier('started_at'));
    }

    public function test_quote_identifier_rejects_injection(): void
    {
        $shift = new MysqlWallClockShift($this->createMock(ConnectionInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $shift->quoteIdentifier('sites`; DROP TABLE sites; --');
    }

    public function test_sqlite_has_no_datetime_columns_to_shift(): void
    {
        $this->assertSame([], (new MysqlWallClockShift(app('db')->connection()))->datetimeColumns());
        $this->assertSame(0, (new MysqlWallClockShift(app('db')->connection()))->shiftDatetimeHours(3));
    }
}
