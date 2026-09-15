<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deskron_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('application_id', 64)->nullable();
            $table->text('api_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deskron_settings');
    }
};
