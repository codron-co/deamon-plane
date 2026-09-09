<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('primary_domain')->unique();
            $table->string('channel');
            $table->string('desired_channel')->nullable();
            $table->string('status')->default('draft');
            $table->string('coolify_app_uuid')->nullable();
            $table->string('coolify_server_uuid')->nullable();
            $table->string('git_repository')->default('https://github.com/codron-co/deamon.git');
            $table->text('app_key_encrypted')->nullable();
            $table->text('agent_secret_encrypted')->nullable();
            $table->string('agent_base_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_health_at')->nullable();
            $table->json('last_health_payload')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('channel');
            $table->index('status');
            $table->index('coolify_app_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
