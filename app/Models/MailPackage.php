<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * CoMail package: limits Plane enforces on every mailbox/alias/domain write.
 * `sites.mail_package_id` null = the default package (exactly one is_default).
 */
class MailPackage extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'mailbox_limit',
        'storage_mb',
        'mailbox_max_quota_mb',
        'alias_limit',
        'domain_limit',
        'price_monthly',
        'currency',
        'is_default',
        'is_active',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mailbox_limit' => 'integer',
            'storage_mb' => 'integer',
            'mailbox_max_quota_mb' => 'integer',
            'alias_limit' => 'integer',
            'domain_limit' => 'integer',
            'price_monthly' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    /**
     * The default package; recreated as "Ücretsiz" when somebody removed every row.
     */
    public static function default(): self
    {
        $default = self::query()->where('is_default', true)->orderBy('sort')->first();
        if ($default instanceof self) {
            return $default;
        }

        $first = self::query()->orderBy('sort')->first();
        if ($first instanceof self) {
            $first->forceFill(['is_default' => true])->save();

            return $first;
        }

        return self::query()->create([
            'name' => 'Ücretsiz',
            'slug' => 'free',
            'mailbox_limit' => 1,
            'storage_mb' => 1024,
            'mailbox_max_quota_mb' => 1024,
            'alias_limit' => 10,
            'domain_limit' => null,
            'currency' => 'TRY',
            'is_default' => true,
            'is_active' => true,
            'sort' => 0,
        ]);
    }

    public static function forSite(Site $site): self
    {
        if (filled($site->mail_package_id)) {
            $package = $site->relationLoaded('mailPackage') ? $site->mailPackage : $site->mailPackage()->first();
            if ($package instanceof self) {
                return $package;
            }
        }

        return self::default();
    }

    public function markAsDefault(): void
    {
        DB::transaction(function (): void {
            self::query()->whereKeyNot($this->getKey())->update(['is_default' => false]);
            $this->forceFill(['is_default' => true, 'is_active' => true])->save();
        });
    }

    public function defaultMailboxQuotaMb(): int
    {
        return max(1, min(1024, $this->mailbox_max_quota_mb));
    }

    /**
     * Public shape for the site API (no prices: the CMS shows limits only).
     *
     * @return array{name: string, slug: string, mailbox_limit: int, storage_mb: int, mailbox_max_quota_mb: int, alias_limit: int, domain_limit: int|null}
     */
    public function toLimitsArray(): array
    {
        return [
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'mailbox_limit' => (int) $this->mailbox_limit,
            'storage_mb' => (int) $this->storage_mb,
            'mailbox_max_quota_mb' => (int) $this->mailbox_max_quota_mb,
            'alias_limit' => (int) $this->alias_limit,
            'domain_limit' => $this->domain_limit === null ? null : (int) $this->domain_limit,
        ];
    }
}
