<?php

namespace App\Models;

use Database\Factories\ThemeGitConnectionRepoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeGitConnectionRepo extends Model
{
    /** @use HasFactory<ThemeGitConnectionRepoFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'theme_git_connection_id',
        'repo_full_name',
        'github_repo_id',
        'default_branch',
        'is_private',
        'html_url',
        'included',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'included' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ThemeGitConnection::class, 'theme_git_connection_id');
    }

    public function shortName(): string
    {
        $full = $this->repo_full_name;

        return str_contains($full, '/')
            ? (string) substr($full, strrpos($full, '/') + 1)
            : $full;
    }
}
