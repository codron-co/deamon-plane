<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('health_unhealthy')->default(false)->after('last_health_payload');
            $table->timestamp('health_verdict_at')->nullable()->after('health_unhealthy');
            $table->boolean('app_has_issues')->default(false)->after('last_app_health_payload');
            $table->unsignedSmallInteger('app_health_issue_count')->default(0)->after('app_has_issues');
            $table->timestamp('app_health_verdict_at')->nullable()->after('app_health_issue_count');

            $table->index('health_unhealthy');
            $table->index('app_has_issues');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['health_unhealthy']);
            $table->dropIndex(['app_has_issues']);
            $table->dropColumn([
                'health_unhealthy',
                'health_verdict_at',
                'app_has_issues',
                'app_health_issue_count',
                'app_health_verdict_at',
            ]);
        });
    }
};
