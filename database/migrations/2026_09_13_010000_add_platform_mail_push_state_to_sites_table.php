<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('platform_mail_pushed_at')->nullable()->after('platform_mail_recipient');
            $table->timestamp('platform_mail_push_failed_at')->nullable()->after('platform_mail_pushed_at');
            $table->string('platform_mail_push_error', 64)->nullable()->after('platform_mail_push_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'platform_mail_pushed_at',
                'platform_mail_push_failed_at',
                'platform_mail_push_error',
            ]);
        });
    }
};
