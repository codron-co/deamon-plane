<?php

use App\Enums\ThemeGitConnectionKind;
use App\Models\GithubSetting;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $connection = ThemeGitConnection::query()->orderBy('id')->first();
        if ($connection !== null && ThemeGitConnection::query()->count() === 1) {
            Theme::query()
                ->whereNull('theme_git_connection_id')
                ->update(['theme_git_connection_id' => $connection->id]);
        }

        $settings = GithubSetting::query()->first();
        if ($settings === null || ThemeGitConnection::query()->doesntExist()) {
            return;
        }

        $settings->installation_id = null;
        if (ThemeGitConnection::query()->where('kind', ThemeGitConnectionKind::Pat)->exists()) {
            $settings->token = null;
        }
        $settings->save();
    }

    public function down(): void
    {
        // Irreversible data backfill.
    }
};
