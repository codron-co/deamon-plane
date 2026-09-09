<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('private_key_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('coolify_settings', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
