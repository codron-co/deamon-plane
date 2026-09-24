<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Last pushed commit of one repo branch and the last CI verdict for it.
 * `repo_full_name` is stored lower-case (GitHub owner/name is case-insensitive).
 *
 * @property int $id
 * @property string $repo_full_name
 * @property string $branch
 * @property string|null $head_sha
 * @property Carbon|null $pushed_at
 * @property string|null $ci_status
 * @property string|null $ci_sha
 * @property Carbon|null $ci_at
 */
class CiBranchHead extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'repo_full_name',
        'branch',
        'head_sha',
        'pushed_at',
        'ci_status',
        'ci_sha',
        'ci_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pushed_at' => 'datetime',
            'ci_at' => 'datetime',
        ];
    }

    public static function normalizeRepo(string $repo): string
    {
        return strtolower(trim($repo));
    }

    public static function for(string $repo, string $branch): ?self
    {
        return self::query()
            ->where('repo_full_name', self::normalizeRepo($repo))
            ->where('branch', $branch)
            ->first();
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function label(): string
    {
        return $this->repo_full_name.'@'.$this->branch;
    }
}
