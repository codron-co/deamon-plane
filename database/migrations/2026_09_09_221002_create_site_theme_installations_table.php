<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_theme_installations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->ulid('theme_id');
            $table->string('ref')->default('main');
            $table->string('pinned_sha')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('auto_update')->default(false);
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('updated_from_webhook_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'theme_id']);
            $table->index(['theme_id', 'auto_update']);
            $table->index(['site_id', 'is_active']);
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('theme_id')->references('id')->on('themes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_theme_installations');
    }
};
