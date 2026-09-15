<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_theme_installations', function (Blueprint $table) {
            $table->string('previous_pinned_sha')->nullable()->after('pinned_sha');
            $table->string('last_sync_task_id')->nullable()->after('pending_sync_after_deploy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_theme_installations', function (Blueprint $table) {
            $table->dropColumn(['previous_pinned_sha', 'last_sync_task_id']);
        });
    }
};
