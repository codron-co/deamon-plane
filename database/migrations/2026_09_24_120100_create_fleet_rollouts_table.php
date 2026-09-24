<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One CI-gated CMS rollout per green commit of a channel branch: canary sites
 * first, then the rest of the `ci`-gated fleet on that channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_rollouts', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->string('sha', 64);
            $table->string('status', 16);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stage_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('summary')->nullable();
            $table->text('halted_reason')->nullable();
            $table->foreignId('halted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index(['channel', 'sha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_rollouts');
    }
};
