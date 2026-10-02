<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-side organisation of the fleet: free-form tags and an importance
 * level per site. Plane metadata only — nothing here is pushed to a site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 32)->unique();
            $table->string('color', 16)->default('gray');
            $table->timestamps();
        });

        Schema::create('site_tag_assignments', function (Blueprint $table) {
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('site_tag_id')->constrained('site_tags')->cascadeOnDelete();
            $table->primary(['site_id', 'site_tag_id']);
            $table->index('site_tag_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            // 0 normal, 1 important, 2 critical (App\Enums\SiteImportance): sortable as a number.
            $table->unsignedTinyInteger('importance')->default(0)->index();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['importance']);
            $table->dropColumn('importance');
        });

        Schema::dropIfExists('site_tag_assignments');
        Schema::dropIfExists('site_tags');
    }
};
