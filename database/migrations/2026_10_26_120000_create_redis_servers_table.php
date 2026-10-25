<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redis_servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(6379);
            $table->string('admin_username');
            $table->text('admin_password');
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->json('last_probe_payload')->nullable();
            $table->timestamps();
        });

        // redis_shared = false: the `redis` service in the site's own compose stack.
        // The server, DB pair and user stay reserved after a move back to local, so a
        // second move reuses them (and the pair is never handed to another site).
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignUlid('redis_server_id')->nullable()->after('db_username')
                ->constrained('redis_servers')->restrictOnDelete();
            $table->unsignedSmallInteger('redis_db')->nullable()->after('redis_server_id');
            $table->string('redis_username', 64)->nullable()->after('redis_db');
            $table->text('redis_password_encrypted')->nullable()->after('redis_username');
            $table->boolean('redis_shared')->default(false)->after('redis_password_encrypted');

            $table->unique(['redis_server_id', 'redis_db']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // The foreign key leans on the unique index in MySQL: drop it first.
            $table->dropForeign(['redis_server_id']);
            $table->dropUnique(['redis_server_id', 'redis_db']);
            $table->dropColumn(['redis_server_id', 'redis_db', 'redis_username', 'redis_password_encrypted', 'redis_shared']);
        });

        Schema::dropIfExists('redis_servers');
    }
};
