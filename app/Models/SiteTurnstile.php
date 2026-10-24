<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir sitenin Turnstile istenen durumu ve widget ataması; CMS'e
 * POST /turnstile ile itilir. Site admini mod ve formları CMS'te de
 * değiştirebilir; Plane'in bir sonraki gönderimi kazanır.
 */
class SiteTurnstile extends Model
{
    use HasUlids;

    public const MODE_INTERACTION_ONLY = 'interaction-only';

    public const MODE_MANAGED = 'managed';

    public const MODE_NON_INTERACTIVE = 'non-interactive';

    public const MODE_INVISIBLE = 'invisible';

    /** Operatörün seçebildiği modlar (CMS ile aynı). */
    public const MODES = [
        self::MODE_INTERACTION_ONLY,
        self::MODE_MANAGED,
        self::MODE_NON_INTERACTIVE,
        self::MODE_INVISIBLE,
    ];

    /** Cloudflare widget modları: site her birinden bir anahtar alır. */
    public const WIDGET_MODES = [
        self::MODE_MANAGED,
        self::MODE_NON_INTERACTIVE,
        self::MODE_INVISIBLE,
    ];

    public const FORMS = ['forms', 'customer_auth', 'commerce', 'admin_auth'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'enabled',
        'mode',
        'forms',
        'cloudflare_setting_id',
        'hostnames',
        'widget_ids',
        'synced_at',
        'sync_error',
        'changed_at',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'forms' => 'array',
            'hostnames' => 'array',
            'widget_ids' => 'array',
            'synced_at' => 'datetime',
            'changed_at' => 'datetime',
            'pushed_at' => 'datetime',
            'push_failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<CloudflareSetting, $this>
     */
    public function cloudflareAccount(): BelongsTo
    {
        return $this->belongsTo(CloudflareSetting::class, 'cloudflare_setting_id');
    }

    public static function widgetMode(string $mode): string
    {
        return $mode === self::MODE_INTERACTION_ONLY ? self::MODE_MANAGED : $mode;
    }

    /**
     * @return list<string>
     */
    public function formList(): array
    {
        return is_array($this->forms) ? array_values(array_intersect(self::FORMS, $this->forms)) : self::FORMS;
    }

    /**
     * @return list<string>
     */
    public function hostnameList(): array
    {
        return is_array($this->hostnames) ? array_values(array_filter($this->hostnames, 'is_string')) : [];
    }

    /**
     * @return array<string, string> widget modu => turnstile_widgets.id
     */
    public function widgetIdMap(): array
    {
        $ids = is_array($this->widget_ids) ? $this->widget_ids : [];

        return array_filter(
            array_intersect_key($ids, array_flip(self::WIDGET_MODES)),
            fn ($id): bool => is_string($id) && $id !== '',
        );
    }

    /**
     * @return array<string, TurnstileWidget>
     */
    public function widgets(): array
    {
        $map = $this->widgetIdMap();
        if ($map === []) {
            return [];
        }

        $widgets = TurnstileWidget::query()->whereIn('id', array_values($map))->get()->keyBy('id');
        $out = [];
        foreach ($map as $mode => $id) {
            if ($widgets->has($id)) {
                $out[$mode] = $widgets->get($id);
            }
        }

        return $out;
    }

    /**
     * CMS gövdesi. Gizli anahtar içerir: log'a, audit'e yazılmaz.
     *
     * @return array<string, mixed>
     */
    public function pushPayload(): array
    {
        $keys = [];
        foreach ($this->widgets() as $mode => $widget) {
            $keys[$mode] = ['sitekey' => (string) $widget->sitekey, 'secret' => (string) $widget->secret];
        }

        return [
            'enabled' => (bool) $this->enabled,
            'mode' => in_array($this->mode, self::MODES, true) ? $this->mode : self::MODE_INTERACTION_ONLY,
            'forms' => $this->formList(),
            'hostnames' => $this->hostnameList(),
            'keys' => $keys === [] ? null : $keys,
        ];
    }

    /**
     * Same rule as SiteSearchIntegration::pushState().
     */
    public function pushState(): string
    {
        if ($this->push_failed_at !== null && ($this->pushed_at === null || $this->push_failed_at->gt($this->pushed_at))) {
            return 'failed';
        }

        if ($this->changed_at !== null && ($this->pushed_at === null || $this->pushed_at->lt($this->changed_at))) {
            return 'pending';
        }

        return $this->pushed_at !== null ? 'ok' : 'never';
    }
}
