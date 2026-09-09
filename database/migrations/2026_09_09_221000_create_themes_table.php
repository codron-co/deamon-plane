<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('theme_id')->unique();
            $table->string('name')->nullable();
            $table->string('repo_full_name')->unique();
            $table->string('default_ref')->default('main');
            $table->string('visibility')->default('private');
            $table->string('minimum_deamon_version')->nullable();
            $table->string('latest_sha')->nullable();
            $table->string('latest_tag')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
