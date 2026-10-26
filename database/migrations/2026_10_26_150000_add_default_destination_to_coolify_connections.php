<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coolify refuses to create an app without destination_uuid once the server has
 * more than one destination (Docker network). Provision sends this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coolify_connections', function (Blueprint $table) {
            $table->string('default_destination_uuid', 64)->nullable()->after('default_git_source_kind');
        });
    }

    public function down(): void
    {
        Schema::table('coolify_connections', function (Blueprint $table) {
            $table->dropColumn('default_destination_uuid');
        });
    }
};
