<?php

namespace App\Services\Security;

use App\Models\SiteSecurityEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filters of the fleet event stream, shared by the screen (GET query) and the
 * MCP tool (arguments): free text, minimum severity, site, rule, IP, period.
 */
final class SecurityEventQuery
{
    public const PERIODS = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public const SEVERITIES = ['info' => 0, 'warning' => 1, 'critical' => 2];

    /**
     * @param  'asc'|'desc'  $direction
     */
    public function __construct(
        public readonly string $search = '',
        public readonly string $severity = '',
        public readonly string $siteId = '',
        public readonly string $rule = '',
        public readonly string $ip = '',
        public readonly string $period = '24h',
        public readonly string $direction = 'desc',
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::fromArray([
            'q' => $request->query('q'),
            'severity' => $request->query('severity'),
            'site' => $request->query('site'),
            'rule' => $request->query('rule'),
            'ip' => $request->query('ip'),
            'period' => $request->query('period'),
            'dir' => $request->query('dir'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $severity = (string) ($input['severity'] ?? '');
        $period = (string) ($input['period'] ?? '');

        return new self(
            search: mb_substr(trim((string) ($input['q'] ?? '')), 0, 100),
            severity: array_key_exists($severity, self::SEVERITIES) ? $severity : '',
            siteId: preg_match('/\A[0-9A-Za-z]{26}\z/', (string) ($input['site'] ?? '')) === 1 ? (string) $input['site'] : '',
            rule: preg_replace('/[^A-Za-z0-9_.:-]/', '', mb_substr((string) ($input['rule'] ?? ''), 0, 60)) ?? '',
            ip: filter_var(trim((string) ($input['ip'] ?? '')), FILTER_VALIDATE_IP) !== false ? trim((string) $input['ip']) : '',
            period: array_key_exists($period, self::PERIODS) ? $period : '24h',
            direction: ($input['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        );
    }

    /**
     * @return Builder<SiteSecurityEvent>
     */
    public function builder(): Builder
    {
        $like = '%'.addcslashes($this->search, '%_\\').'%';

        return SiteSecurityEvent::query()
            ->with('site:id,slug,name')
            ->where('occurred_at', '>=', now()->subDays(self::PERIODS[$this->period]))
            ->when($this->severity !== '', fn (Builder $query) => $query->where('severity', '>=', self::SEVERITIES[$this->severity]))
            ->when($this->siteId !== '', fn (Builder $query) => $query->where('site_id', $this->siteId))
            ->when($this->rule !== '', fn (Builder $query) => $query->where('rule', $this->rule))
            ->when($this->ip !== '', fn (Builder $query) => $query->where('ip', $this->ip))
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('ip', 'like', $like)
                ->orWhere('path', 'like', $like)
                ->orWhere('rule', 'like', $like)
                ->orWhere('user_agent', 'like', $like)))
            ->orderBy('occurred_at', $this->direction)
            ->orderBy('id', $this->direction);
    }

    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return $this->builder()->paginate($perPage)->withQueryString();
    }

    public function active(): bool
    {
        return $this->search !== '' || $this->severity !== '' || $this->siteId !== '' || $this->rule !== ''
            || $this->ip !== '' || $this->period !== '24h';
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        return SiteSecurityEvent::query()->distinct()->orderBy('rule')->limit(100)->pluck('rule')->all();
    }
}
