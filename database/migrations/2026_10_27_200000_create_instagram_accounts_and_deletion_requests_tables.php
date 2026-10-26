<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which site an Instagram user (app-scoped id from the token exchange) is connected to,
        // so Meta's deauthorize / data deletion callbacks can reach the right site.
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('ig_user_id', 64)->index();
            $table->string('username', 120)->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'ig_user_id']);
        });

        Schema::create('instagram_deletion_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('confirmation_code', 32)->unique();
            $table->string('type', 16);
            $table->string('ig_user_id', 64)->index();
            $table->string('status', 16)->default('received');
            $table->json('site_ids')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_deletion_requests');
        Schema::dropIfExists('instagram_accounts');
    }
};
