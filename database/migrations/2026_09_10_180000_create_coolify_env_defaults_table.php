<?php

use App\Support\CoolifyEnvDefaultCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coolify_env_defaults', function (Blueprint $table) {
            $table->id();
            $table->string('pack', 32);
            $table->string('key', 120);
            $table->string('kind', 32);
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->string('notes', 64)->nullable();
            $table->timestamps();

            $table->unique(['pack', 'key']);
            $table->index(['pack', 'sort']);
        });

        $now = now();

        foreach (CoolifyEnvDefaultCatalog::rows() as $row) {
            DB::table('coolify_env_defaults')->insert([
                ...$row,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coolify_env_defaults');
    }
};
