<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user table layout (visible columns + sort) for ops lists, keyed by list
     * name. One JSON column rather than typed columns per list, because the column
     * catalogue of a list changes far more often than the schema should.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('list_preferences')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('list_preferences');
        });
    }
};
