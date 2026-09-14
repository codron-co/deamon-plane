<?php

use App\Enums\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coolify env catalog is now one row set per git channel (main / beta / alpha), synced from the
 * CMS `.env.production.example` on that branch. Build-pack catalogs (dockercompose / dockerfile)
 * and in-Plane editing are gone. Existing compose rows are carried over to every channel so
 * deploys keep working until the first GitHub sync replaces them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coolify_env_catalog_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 32)->unique();
            $table->string('repo_full_name', 200)->nullable();
            $table->string('path', 200)->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('coolify_env_defaults')) {
            return;
        }

        $legacy = DB::table('coolify_env_defaults')
            ->where('pack', 'dockercompose')
            ->orderBy('sort')
            ->orderBy('key')
            ->get();

        Schema::create('coolify_env_defaults_next', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 32);
            $table->string('key', 120);
            $table->string('kind', 32);
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'key']);
            $table->index(['channel', 'sort']);
        });

        $now = now();
        foreach (Channel::cases() as $channel) {
            foreach ($legacy as $row) {
                DB::table('coolify_env_defaults_next')->insert([
                    'channel' => $channel->value,
                    'key' => $row->key,
                    'kind' => $row->kind,
                    'value' => $row->value,
                    'is_secret' => (bool) $row->is_secret,
                    'sort' => (int) $row->sort,
                    'description' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::drop('coolify_env_defaults');
        Schema::rename('coolify_env_defaults_next', 'coolify_env_defaults');
    }

    public function down(): void
    {
        Schema::dropIfExists('coolify_env_catalog_sources');

        if (! Schema::hasTable('coolify_env_defaults')) {
            return;
        }

        $rows = DB::table('coolify_env_defaults')->where('channel', Channel::Main->value)->get();

        Schema::create('coolify_env_defaults_prev', function (Blueprint $table): void {
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
        foreach ($rows as $row) {
            DB::table('coolify_env_defaults_prev')->insert([
                'pack' => 'dockercompose',
                'key' => $row->key,
                'kind' => $row->kind,
                'value' => $row->value,
                'is_secret' => (bool) $row->is_secret,
                'sort' => (int) $row->sort,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::drop('coolify_env_defaults');
        Schema::rename('coolify_env_defaults_prev', 'coolify_env_defaults');
    }
};
