<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('last_live_http_status')->nullable()->after('last_health_payload');
            $table->timestamp('last_live_checked_at')->nullable()->after('last_live_http_status');
            $table->string('last_live_favicon_url')->nullable()->after('last_live_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'last_live_http_status',
                'last_live_checked_at',
                'last_live_favicon_url',
            ]);
        });
    }
};
