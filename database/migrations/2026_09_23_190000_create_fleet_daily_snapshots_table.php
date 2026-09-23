<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per calendar day (app timezone): the Sites summary tile counts,
        // so the tiles can show a trend against the previous day.
        Schema::create('fleet_daily_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('unhealthy')->default(0);
            $table->unsignedInteger('failed_deploys')->default(0);
            $table->unsignedInteger('app_issues')->default(0);
            $table->unsignedInteger('git_themes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_daily_snapshots');
    }
};
