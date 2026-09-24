<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Unhealthy agent answers in a row; the down alert waits for a few.
            $table->unsignedSmallInteger('health_fail_streak')->default(0)->after('last_health_notify_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('health_fail_streak');
        });
    }
};
