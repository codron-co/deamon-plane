<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_background_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type');
            $table->string('title');
            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['actor_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_background_jobs');
    }
};
