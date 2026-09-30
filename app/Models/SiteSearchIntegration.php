<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * Desired state of one site's search engine verification and measurement ids,
 * pushed to the CMS over the signed agent (POST /search-integrations).
 *
 * Validation mirrors the CMS agent (`SearchIntegrationsPlaneSync`): a verification
 * token is only the meta `content` value, GA4 is `G-…`, GTM is `GTM-…`. Nothing
 * here is secret — every value ends up in the public page source.
 */
class SiteSearchIntegration extends Model
{
    use HasUlids;

    public const MODES = ['off', 'ga4', 'gtm'];

    /** Meta `content` value: no quotes, spaces or `<>`. Same set as the CMS agent. */
    public const TOKEN_PATTERN = '/\A[A-Za-z0-9._:+\/=-]+\z/';

    /** `google{token}.html` route charset on the CMS. */
    public const FILE_TOKEN_PATTERN = '/\A[A-Za-z0-9_-]+\z/';

    public const GTM_PATTERN = '/\AGTM-[A-Z0-9]+\z/i';

    public const GA4_PATTERN = '/\AG-[A-Z0-9]+\z/i';

    /**
     * Plane column => CMS dot key (`SearchIntegrationsSettingsRepository::PLANE_MANAGED_FIELDS`).
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'google_verification' => 'verifications.google',
        'bing_verification' => 'verifications.bing',
        'yandex_verification' => 'verifications.yandex',
        'google_file_token' => 'verification_files.google',
        'google_mode' => 'google.mode',
        'gtm_id' => 'google.gtm_id',
        'ga4_id' => 'google.ga4_id',
        'yandex_metrica_id' => 'yandex_metrica.counter_id',
        'clarity_id' => 'clarity.project_id',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'google_verification',
        'bing_verification',
        'yandex_verification',
        'google_file_token',
        'google_mode',
        'gtm_id',
        'ga4_id',
        'yandex_metrica_id',
        'clarity_id',
        'enable_module',
        'managed_fields',
        'changed_at',
        'pushed_at',
        'push_failed_at',
        'push_error',
        'pulled_at',
        'site_module_enabled',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enable_module' => 'boolean',
            'managed_fields' => 'array',
            'changed_at' => 'datetime',
            'pushed_at' => 'datetime',
            'push_failed_at' => 'datetime',
            'pulled_at' => 'datetime',
            'site_module_enabled' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Operators paste whatever Search Console shows them: the whole meta tag, or
     * the verification file name. Keep only the value the CMS stores.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalizeInput(array $input): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $column) {
            $value = trim((string) ($input[$column] ?? ''));

            if (in_array($column, ['google_verification', 'bing_verification', 'yandex_verification'], true)
                && preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $value, $match) === 1) {
                $value = trim($match[1]);
            }

            if ($column === 'google_file_token' && preg_match('/\A(?:.*\/)?google([A-Za-z0-9_-]+)\.html\z/', $value, $match) === 1) {
                $value = $match[1];
            }

            if (in_array($column, ['gtm_id', 'ga4_id'], true)) {
                $value = strtoupper($value);
            }

            if ($column === 'google_mode') {
                $value = strtolower($value) ?: 'off';
            }

            $values[$column] = $value;
        }

        return $values;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $token = ['nullable', 'string', 'max:191', 'regex:'.self::TOKEN_PATTERN];

        return [
            'google_verification' => $token,
            'bing_verification' => $token,
            'yandex_verification' => $token,
            'google_file_token' => ['nullable', 'string', 'max:191', 'regex:'.self::FILE_TOKEN_PATTERN],
            'google_mode' => ['required', 'in:'.implode(',', self::MODES)],
            'gtm_id' => ['nullable', 'required_if:google_mode,gtm', 'string', 'max:32', 'regex:'.self::GTM_PATTERN],
            'ga4_id' => ['nullable', 'required_if:google_mode,ga4', 'string', 'max:32', 'regex:'.self::GA4_PATTERN],
            'yandex_metrica_id' => ['nullable', 'string', 'max:16', 'regex:/\A\d+\z/'],
            'clarity_id' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9]+\z/'],
            'enable_module' => ['nullable', 'boolean'],
        ];
    }

    /**
     * CMS dot keys to push. An empty field Plane never set is left alone, so the
     * first save from Plane does not wipe values the site entered itself; a field
     * that was set (or pulled) once is pushed even when cleared.
     *
     * @param  array<string, string>  $values  column => value
     * @param  list<string>  $previous
     * @return list<string>
     */
    public static function managedAfter(array $values, array $previous): array
    {
        $managed = array_fill_keys(array_intersect($previous, self::FIELDS), true);

        foreach (self::FIELDS as $column => $key) {
            $value = (string) ($values[$column] ?? '');
            if ($column === 'google_mode' ? $value !== 'off' : $value !== '') {
                $managed[$key] = true;
            }
        }

        return array_values(array_intersect(self::FIELDS, array_keys($managed)));
    }

    /**
     * Body for POST /search-integrations (compact JSON, nested like the CMS setting).
     *
     * @return array<string, mixed>
     */
    public function pushPayload(): array
    {
        $managed = is_array($this->managed_fields) ? $this->managed_fields : [];
        $payload = [];

        foreach (self::FIELDS as $column => $key) {
            if (! in_array($key, $managed, true)) {
                continue;
            }

            $value = (string) ($this->getAttribute($column) ?? '');
            Arr::set($payload, $key, $column === 'google_mode' ? ($value ?: 'off') : $value);
        }

        if ($this->enable_module) {
            $payload['enable_module'] = true;
        }

        return $payload;
    }

    /**
     * Column values from a CMS GET /search-integrations `search_integrations` block.
     * Values the CMS would reject (hand-edited admin input) are dropped to empty.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, string>
     */
    public static function valuesFromRemote(array $remote): array
    {
        $raw = [];
        foreach (self::FIELDS as $column => $key) {
            $raw[$column] = (string) (Arr::get($remote, $key) ?? '');
        }

        $values = self::normalizeInput($raw);
        $patterns = [
            'google_verification' => self::TOKEN_PATTERN,
            'bing_verification' => self::TOKEN_PATTERN,
            'yandex_verification' => self::TOKEN_PATTERN,
            'google_file_token' => self::FILE_TOKEN_PATTERN,
            'gtm_id' => self::GTM_PATTERN,
            'ga4_id' => self::GA4_PATTERN,
            'yandex_metrica_id' => '/\A\d{1,16}\z/',
            'clarity_id' => '/\A[A-Za-z0-9]{1,64}\z/',
        ];

        foreach ($patterns as $column => $pattern) {
            if ($values[$column] !== '' && preg_match($pattern, $values[$column]) !== 1) {
                $values[$column] = '';
            }
        }

        if (! in_array($values['google_mode'], self::MODES, true)) {
            $values['google_mode'] = 'off';
        }

        return $values;
    }

    public function hasSearchConsole(): bool
    {
        return filled($this->google_verification) || filled($this->google_file_token);
    }

    /**
     * `ga4` | `gtm` | `off` — a mode without its id measures nothing.
     */
    public function measurement(): string
    {
        return match ($this->google_mode) {
            'ga4' => filled($this->ga4_id) ? 'ga4' : 'off',
            'gtm' => filled($this->gtm_id) ? 'gtm' : 'off',
            default => 'off',
        };
    }

    public function measurementId(): ?string
    {
        return match ($this->measurement()) {
            'ga4' => $this->ga4_id,
            'gtm' => $this->gtm_id,
            default => null,
        };
    }

    /**
     * `failed` | `pending` | `ok` | `never`.
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
