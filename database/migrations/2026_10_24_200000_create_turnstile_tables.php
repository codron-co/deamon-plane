<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloudflare Turnstile bot koruması.
 *
 * turnstile_widgets: Plane'in bir Cloudflare hesabında açtığı havuz widget'ları.
 * Ücretsiz plan hesap başına 20 widget, widget başına 10 alan adı verir; bu yüzden
 * her site ayrı widget almaz, aynı moddaki widget'lar 10 alan adına kadar paylaşılır.
 *
 * site_turnstiles: sitenin istenen durumu (mod, korunan formlar) ve hangi widget'ta
 * hangi alan adlarıyla durduğu; CMS'e imzalı agent ile (POST /turnstile) itilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnstile_widgets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('cloudflare_setting_id')->constrained('cloudflare_settings')->cascadeOnDelete();
            $table->string('mode', 20);
            $table->string('name', 120);
            $table->string('sitekey', 100)->unique();
            $table->text('secret');
            $table->json('domains');
            $table->timestamps();

            $table->index(['cloudflare_setting_id', 'mode']);
        });

        Schema::create('site_turnstiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('mode', 24)->default('interaction-only');
            $table->json('forms')->nullable();

            // Widget ataması: hesap, alan adları ve mod başına widget id'si.
            $table->foreignId('cloudflare_setting_id')->nullable()->constrained('cloudflare_settings')->nullOnDelete();
            $table->json('hostnames')->nullable();
            $table->json('widget_ids')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('sync_error', 255)->nullable();

            // Son operatör değişikliği; pushed_at daha eskiyse gönderim bekliyor.
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('push_failed_at')->nullable();
            $table->string('push_error', 255)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_turnstiles');
        Schema::dropIfExists('turnstile_widgets');
    }
};
