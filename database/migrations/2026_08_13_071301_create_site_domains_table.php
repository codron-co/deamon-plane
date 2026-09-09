<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('coolify_domain_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_domains');
    }
};
