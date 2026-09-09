<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coolify_settings', function (Blueprint $table) {
            $table->id();
            $table->string('base_url')->nullable();
            $table->text('api_token')->nullable();
            $table->string('default_project_uuid')->nullable();
            $table->string('default_server_uuid')->nullable();
            $table->string('github_app_uuid')->nullable();
            $table->string('private_key_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coolify_settings');
    }
};
