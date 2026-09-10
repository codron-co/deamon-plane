<?php

use App\Support\MysqlWallClockShift;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * DATETIME columns store a naive wall clock and ignore MySQL time_zone.
     * Existing UTC DATETIME values get +3 so they match Europe/Istanbul.
     *
     * TIMESTAMP columns are not updated. Plane MySQL session/server time_zone
     * is +03:00; adding 3 hours here would make those times 3 hours fast.
     */
    public function up(): void
    {
        (new MysqlWallClockShift(DB::connection()))->shiftDatetimeHours(3);
    }

    public function down(): void
    {
        (new MysqlWallClockShift(DB::connection()))->shiftDatetimeHours(-3);
    }
};
