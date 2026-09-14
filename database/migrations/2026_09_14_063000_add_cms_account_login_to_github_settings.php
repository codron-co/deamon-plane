<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_settings', function (Blueprint $table) {
            $table->string('cms_account_login')->nullable()->after('installation_id');
        });
    }

    public function down(): void
    {
        Schema::table('github_settings', function (Blueprint $table) {
            $table->dropColumn('cms_account_login');
        });
    }
};
