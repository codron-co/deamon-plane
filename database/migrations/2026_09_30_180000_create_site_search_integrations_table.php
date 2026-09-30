<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desired state for a site's search engine verification and measurement ids
 * (CMS `search-integrations`), pushed over the signed agent. No secrets here:
 * verification tokens and GA4/GTM ids are public in the page source anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_search_integrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained('sites')->cascadeOnDelete();

            $table->string('google_verification', 191)->nullable();
            $table->string('bing_verification', 191)->nullable();
            $table->string('yandex_verification', 191)->nullable();
            $table->string('google_file_token', 191)->nullable();
            $table->string('google_mode', 8)->default('off');
            $table->string('gtm_id', 32)->nullable();
            $table->string('ga4_id', 32)->nullable();
            $table->string('yandex_metrica_id', 16)->nullable();
            $table->string('clarity_id', 64)->nullable();
            $table->boolean('enable_module')->default(true);

            // CMS dot keys Plane owns: filled once set or pulled; an untouched empty field is never pushed.
            $table->json('managed_fields')->nullable();

            // Last operator change of the desired state; pending while pushed_at is older.
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('push_failed_at')->nullable();
            $table->string('push_error', 255)->nullable();
            $table->timestamp('pulled_at')->nullable();
            $table->boolean('site_module_enabled')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_search_integrations');
    }
};
