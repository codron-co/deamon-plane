<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('last_app_health_at')->nullable()->after('last_live_favicon_url');
            $table->json('last_app_health_payload')->nullable()->after('last_app_health_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['last_app_health_at', 'last_app_health_payload']);
        });
    }
};
