<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_settings', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 32)->nullable();
            $table->text('api_token')->nullable();
            $table->string('origin_ipv4')->default('72.62.117.147');
            $table->boolean('proxied')->default(false);
            $table->boolean('mail_template_enabled')->default(true);
            $table->timestamp('last_probe_at')->nullable();
            $table->json('last_probe_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_settings');
    }
};
