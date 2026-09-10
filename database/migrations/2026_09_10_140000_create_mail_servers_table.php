<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('provider');
            $table->text('api_token')->nullable();
            $table->string('hostinger_order_id')->nullable();
            $table->string('mail_domain')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_probe_at')->nullable();
            $table->json('last_probe_payload')->nullable();
            $table->timestamps();

            $table->index('provider');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignUlid('mail_server_id')->nullable()->after('dns_applied_at')->constrained('mail_servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_server_id');
        });

        Schema::dropIfExists('mail_servers');
    }
};
