<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Original build-pack catalog table. The in-code seed (CoolifyEnvDefaultCatalog) is gone:
 * 2026_09_15_000001 reshapes this table per git channel and rows come from the CMS
 * `.env.production.example` via CoolifyEnvCatalogSync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coolify_env_defaults', function (Blueprint $table) {
            $table->id();
            $table->string('pack', 32);
            $table->string('key', 120);
            $table->string('kind', 32);
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->string('notes', 64)->nullable();
            $table->timestamps();

            $table->unique(['pack', 'key']);
            $table->index(['pack', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coolify_env_defaults');
    }
};
