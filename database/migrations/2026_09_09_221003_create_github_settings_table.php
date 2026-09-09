<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_settings', function (Blueprint $table) {
            $table->id();
            $table->string('org')->nullable();
            $table->text('token')->nullable();
            $table->string('app_id')->nullable();
            $table->string('installation_id')->nullable();
            $table->text('private_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_settings');
    }
};
