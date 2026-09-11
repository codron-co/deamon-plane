<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_mail_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(465);
            $table->string('encryption', 16)->default('ssl');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('default_admin_recipient')->nullable();
            $table->json('notifications')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->json('platform_notification_overrides')->nullable()->after('mail_domain');
            $table->string('platform_mail_recipient')->nullable()->after('platform_notification_overrides');
            $table->string('last_notified_deamon_version', 32)->nullable()->after('last_health_payload');
            $table->string('last_health_notify_status', 32)->nullable()->after('last_notified_deamon_version');
            $table->timestamp('last_health_notify_at')->nullable()->after('last_health_notify_status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'platform_notification_overrides',
                'platform_mail_recipient',
                'last_notified_deamon_version',
                'last_health_notify_status',
                'last_health_notify_at',
            ]);
        });

        Schema::dropIfExists('platform_mail_settings');
    }
};
