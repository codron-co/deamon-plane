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
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('deskron_pushed_at')->nullable();
            $table->timestamp('deskron_push_failed_at')->nullable();
            $table->string('deskron_push_error', 64)->nullable();
        });

        Schema::table('deskron_settings', function (Blueprint $table): void {
            $table->timestamp('last_pushed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deskron_settings', function (Blueprint $table): void {
            $table->dropColumn('last_pushed_at');
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['deskron_pushed_at', 'deskron_push_failed_at', 'deskron_push_error']);
        });
    }
};
