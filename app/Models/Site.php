<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'primary_domain',
        'channel',
        'desired_channel',
        'status',
        'coolify_app_uuid',
        'coolify_server_uuid',
        'git_repository',
        'app_key_encrypted',
        'agent_secret_encrypted',
        'agent_base_url',
        'notes',
        'last_health_at',
        'last_health_payload',
    ];

    /**
     * Secrets must never appear in arrays, JSON, or logs.
     *
     * @var list<string>
     */
    protected $hidden = [
        'app_key_encrypted',
        'agent_secret_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'desired_channel' => Channel::class,
            'status' => SiteStatus::class,
            'app_key_encrypted' => 'encrypted',
            'agent_secret_encrypted' => 'encrypted',
            'last_health_at' => 'datetime',
            'last_health_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site): void {
            if ($site->status === null) {
                $site->status = SiteStatus::Draft;
            }

            if (blank($site->git_repository)) {
                $site->git_repository = config('ops.deamon.repository')
                    ?: 'https://github.com/codron-co/deamon.git';
            }
        });

        static::saving(function (Site $site): void {
            if ($site->channel instanceof Channel) {
                Channel::assertAllowed($site->channel);
            }

            if ($site->desired_channel instanceof Channel) {
                Channel::assertAllowed($site->desired_channel);
            }
        });
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    public function primaryDomainRecord(): HasOne
    {
        return $this->hasOne(SiteDomain::class)->where('is_primary', true);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function canTransitionTo(SiteStatus $next): bool
    {
        $current = $this->status ?? SiteStatus::Draft;

        return $current->canTransitionTo($next);
    }

    public function transitionTo(SiteStatus $next): void
    {
        if (! $this->canTransitionTo($next)) {
            $from = ($this->status ?? SiteStatus::Draft)->value;

            throw new LogicException("Cannot transition site status from [{$from}] to [{$next->value}].");
        }

        $this->status = $next;
    }
}
