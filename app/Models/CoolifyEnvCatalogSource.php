<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a channel's Coolify env catalog came from: CMS repo + branch + `.env.production.example`
 * commit. One row per channel; written by CoolifyEnvCatalogSync only.
 */
class CoolifyEnvCatalogSource extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel',
        'repo_full_name',
        'path',
        'commit_sha',
        'row_count',
        'fetched_at',
        'last_error',
        'last_attempt_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'row_count' => 'integer',
            'fetched_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public static function forChannel(Channel $channel): self
    {
        return static::query()->firstOrNew(['channel' => $channel->value]);
    }

    public function shortSha(): ?string
    {
        $sha = trim((string) $this->commit_sha);

        return $sha !== '' ? substr($sha, 0, 7) : null;
    }

    public function isSynced(): bool
    {
        return $this->fetched_at !== null;
    }

    public function sourceUrl(): ?string
    {
        $repo = trim((string) $this->repo_full_name);
        $path = ltrim(trim((string) $this->path), '/');
        if ($repo === '' || $path === '') {
            return null;
        }

        $ref = filled($this->commit_sha)
            ? (string) $this->commit_sha
            : ($this->channel instanceof Channel ? $this->channel->value : (string) $this->channel);

        return rtrim((string) config('ops.github.web_base', 'https://github.com'), '/')
            .'/'.$repo.'/blob/'.$ref.'/'.$path;
    }
}
