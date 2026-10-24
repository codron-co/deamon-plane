<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(3306);
            $table->string('admin_username');
            $table->text('admin_password');
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->json('last_probe_payload')->nullable();
            $table->timestamps();
        });

        // Null database_server_id = the site's own `mysql` service in its compose stack.
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignUlid('database_server_id')->nullable()->after('mysql_root_password_encrypted')
                ->constrained('database_servers')->restrictOnDelete();
            $table->string('db_database', 64)->nullable()->after('database_server_id');
            $table->string('db_username', 32)->nullable()->after('db_database');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('database_server_id');
            $table->dropColumn(['db_database', 'db_username']);
        });

        Schema::dropIfExists('database_servers');
    }
};
