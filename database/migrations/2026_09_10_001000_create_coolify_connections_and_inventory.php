<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coolify_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->text('api_token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('default_project_uuid')->nullable();
            $table->string('default_server_uuid')->nullable();
            $table->string('default_environment_uuid')->nullable();
            $table->string('default_environment_name')->nullable();
            $table->string('default_git_source_uuid')->nullable();
            $table->string('default_git_source_kind')->nullable();
            $table->boolean('github_apps_list_available')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('coolify_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coolify_connection_id')->constrained('coolify_connections')->cascadeOnDelete();
            $table->string('uuid');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['coolify_connection_id', 'uuid'], 'coolify_servers_connection_uuid_unique');
        });

        Schema::create('coolify_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coolify_connection_id')->constrained('coolify_connections')->cascadeOnDelete();
            $table->string('uuid');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['coolify_connection_id', 'uuid'], 'coolify_projects_connection_uuid_unique');
        });

        Schema::create('coolify_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coolify_connection_id')->constrained('coolify_connections')->cascadeOnDelete();
            $table->string('project_uuid');
            $table->string('uuid');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['coolify_connection_id', 'project_uuid', 'uuid'], 'coolify_envs_connection_project_uuid_unique');
        });

        Schema::create('coolify_git_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coolify_connection_id')->constrained('coolify_connections')->cascadeOnDelete();
            $table->string('kind');
            $table->string('uuid');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['coolify_connection_id', 'kind', 'uuid'], 'coolify_git_sources_connection_kind_uuid_unique');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('coolify_connection_id')->nullable()->after('status')->constrained('coolify_connections')->nullOnDelete();
            $table->string('coolify_project_uuid')->nullable()->after('coolify_server_uuid');
            $table->string('coolify_environment_uuid')->nullable()->after('coolify_project_uuid');
            $table->string('coolify_git_source_uuid')->nullable()->after('coolify_environment_uuid');
            $table->string('coolify_git_source_kind')->nullable()->after('coolify_git_source_uuid');
            $table->boolean('channel_needs_review')->default(false)->after('desired_channel');
        });

        $this->migrateLegacySettings();
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coolify_connection_id');
            $table->dropColumn([
                'coolify_project_uuid',
                'coolify_environment_uuid',
                'coolify_git_source_uuid',
                'coolify_git_source_kind',
                'channel_needs_review',
            ]);
        });

        Schema::dropIfExists('coolify_git_sources');
        Schema::dropIfExists('coolify_environments');
        Schema::dropIfExists('coolify_projects');
        Schema::dropIfExists('coolify_servers');
        Schema::dropIfExists('coolify_connections');
    }

    private function migrateLegacySettings(): void
    {
        if (! Schema::hasTable('coolify_settings')) {
            return;
        }

        $settings = DB::table('coolify_settings')->first();
        if ($settings === null) {
            return;
        }

        if (
            blank($settings->base_url)
            && blank($settings->api_token)
            && blank($settings->webhook_secret ?? null)
            && blank($settings->default_project_uuid)
            && blank($settings->default_server_uuid)
        ) {
            return;
        }

        $connectionId = DB::table('coolify_connections')->insertGetId([
            'name' => 'Coolify',
            'base_url' => $settings->base_url,
            'api_token' => $settings->api_token,
            'webhook_secret' => $settings->webhook_secret ?? null,
            'is_enabled' => true,
            'is_default' => true,
            'default_project_uuid' => $settings->default_project_uuid,
            'default_server_uuid' => $settings->default_server_uuid,
            'default_environment_name' => 'production',
            'default_git_source_uuid' => $settings->github_app_uuid ?: $settings->private_key_uuid,
            'default_git_source_kind' => filled($settings->github_app_uuid)
                ? 'github_app'
                : (filled($settings->private_key_uuid) ? 'deploy_key' : null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (filled($settings->default_server_uuid)) {
            DB::table('coolify_servers')->insert([
                'coolify_connection_id' => $connectionId,
                'uuid' => $settings->default_server_uuid,
                'name' => $settings->default_server_uuid,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (filled($settings->default_project_uuid)) {
            DB::table('coolify_projects')->insert([
                'coolify_connection_id' => $connectionId,
                'uuid' => $settings->default_project_uuid,
                'name' => $settings->default_project_uuid,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (filled($settings->github_app_uuid)) {
            DB::table('coolify_git_sources')->insert([
                'coolify_connection_id' => $connectionId,
                'kind' => 'github_app',
                'uuid' => $settings->github_app_uuid,
                'name' => 'GitHub App',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (filled($settings->private_key_uuid)) {
            DB::table('coolify_git_sources')->insert([
                'coolify_connection_id' => $connectionId,
                'kind' => 'deploy_key',
                'uuid' => $settings->private_key_uuid,
                'name' => 'Deploy key',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
