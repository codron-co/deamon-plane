<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('channel');
            $table->string('trigger');
            $table->string('coolify_deployment_uuid')->nullable();
            $table->string('status')->default('queued');
            $table->string('commit_sha')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('log_excerpt')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index('coolify_deployment_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
