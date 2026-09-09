<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_site_access', function (Blueprint $table) {
            $table->id();
            $table->ulid('theme_id');
            $table->ulid('site_id');
            $table->timestamps();

            $table->unique(['theme_id', 'site_id']);
            $table->foreign('theme_id')->references('id')->on('themes')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_site_access');
    }
};
