<?php

use App\Models\GithubSetting;
use App\Models\ThemeGitConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_settings', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('installation_id');
            $table->string('client_id')->nullable()->after('slug');
            $table->text('client_secret')->nullable()->after('client_id');
        });

        Schema::create('theme_git_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('account_login');
            $table->string('account_type');
            $table->string('installation_id')->nullable();
            $table->string('selection_mode')->default('all');
            $table->string('repo_name_prefix')->nullable();
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->string('kind');
            $table->text('token')->nullable();
            $table->timestamps();

            $table->unique('installation_id');
            $table->index(['status', 'kind']);
            $table->index('account_login');
        });

        Schema::create('theme_git_connection_repos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_git_connection_id')->constrained('theme_git_connections')->cascadeOnDelete();
            $table->string('repo_full_name');
            $table->string('github_repo_id')->nullable();
            $table->string('default_branch')->nullable();
            $table->boolean('is_private')->default(true);
            $table->string('html_url')->nullable();
            $table->boolean('included')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['theme_git_connection_id', 'repo_full_name'], 'theme_git_repos_connection_full_name_unique');
        });

        Schema::table('themes', function (Blueprint $table) {
            $table->foreignId('theme_git_connection_id')
                ->nullable()
                ->after('description')
                ->constrained('theme_git_connections')
                ->nullOnDelete();
        });

        $settings = GithubSetting::query()->first();
        if ($settings !== null) {
            ThemeGitConnection::createFromLegacySettings($settings);
        }
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('theme_git_connection_id');
        });

        Schema::dropIfExists('theme_git_connection_repos');
        Schema::dropIfExists('theme_git_connections');

        Schema::table('github_settings', function (Blueprint $table) {
            $table->dropColumn(['slug', 'client_id', 'client_secret']);
        });
    }
};
