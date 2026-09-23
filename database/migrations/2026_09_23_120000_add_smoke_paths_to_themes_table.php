<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table): void {
            // theme.json `smoke_paths`: storefront pages Plane GETs after install / update /
            // sync (besides "/"); a 5xx there rolls the change back.
            $table->json('smoke_paths')->nullable()->after('minimum_deamon_version');
        });
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table): void {
            $table->dropColumn('smoke_paths');
        });
    }
};
