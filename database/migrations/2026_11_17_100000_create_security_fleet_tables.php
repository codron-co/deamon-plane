<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Events pulled from each CMS (security-alerts module). No request bodies, no
        // secrets; `event_uid` is the CMS row id so a re-poll never duplicates a row.
        Schema::create('site_security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('event_uid', 64);
            $table->timestamp('occurred_at');
            $table->string('type', 40);
            $table->unsignedTinyInteger('severity')->default(0);
            $table->string('rule', 60);
            $table->string('ip', 45);
            $table->string('country', 2)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('created_at')->nullable();

            $table->unique(['site_id', 'event_uid']);
            $table->index('occurred_at');
            $table->index(['site_id', 'occurred_at']);
            $table->index(['ip', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
            $table->index('rule');
        });

        Schema::create('site_security_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->string('cursor', 255)->nullable();
            $table->boolean('supported')->nullable();
            $table->boolean('module_enabled')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        // Fleet-wide IP blocks: Plane keeps the intent, each site's answer lives in `applied`.
        Schema::create('fleet_ip_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('ip', 64);
            $table->string('reason', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->json('applied')->nullable();
            $table->timestamps();

            $table->index(['ip', 'released_at']);
        });

        Schema::create('fleet_allowlist', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('ip', 64);
            $table->string('label', 120)->nullable();
            $table->string('source', 16)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->json('applied')->nullable();
            $table->timestamps();

            $table->index(['ip', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_allowlist');
        Schema::dropIfExists('fleet_ip_blocks');
        Schema::dropIfExists('site_security_cursors');
        Schema::dropIfExists('site_security_events');
    }
};
