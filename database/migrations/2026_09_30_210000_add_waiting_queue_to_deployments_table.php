<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            // A single-site deploy that found the per-host build cap full waits in
            // Plane as status `waiting`. These columns replay it once a slot frees.
            $table->string('queue_action', 32)->nullable()->after('requested_by');
            $table->json('queue_payload')->nullable()->after('queue_action');
            $table->unsignedSmallInteger('queue_attempts')->default(0)->after('queue_payload');
            $table->timestamp('queued_at')->nullable()->after('queue_attempts');

            $table->index(['status', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'queued_at']);
            $table->dropColumn(['queue_action', 'queue_payload', 'queue_attempts', 'queued_at']);
        });
    }
};
