<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_theme_installations', function (Blueprint $table): void {
            // CMS 1.2.32+ `/themes/update` → `customizations`: {kept, conflicts} theme file
            // paths the site edited and the update left alone. Null until an update reports it.
            $table->json('customized_files')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('site_theme_installations', function (Blueprint $table): void {
            $table->dropColumn('customized_files');
        });
    }
};
