<?php

namespace App\Http\Controllers\Ops;

use App\Enums\ThemeGitAccountType;
use App\Enums\ThemeGitSelectionMode;
use App\Enums\ThemeVisibility;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\QueuesOpsJob;
use App\Models\GithubSetting;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubCredentialsException;
use App\Services\Themes\ThemeCatalogSync;
use App\Support\Lists\ListFragment;
use App\Support\PublicAppUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ThemeController extends Controller
{
    use QueuesOpsJob;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Theme::class);

        $search = trim((string) $request->query('q', ''));
        $visibility = (string) $request->query('visibility', '');
        $visibility = in_array($visibility, ThemeVisibility::values(), true) ? $visibility : '';

        $query = Theme::query()
            ->matchingListFilters($search, $visibility)
            ->orderBy('theme_id');

        $connections = ThemeGitConnection::query()
            ->withCount([
                'repos',
                'themes',
                'repos as included_repos_count' => static fn ($builder) => $builder->where('included', true),
            ])
            ->orderBy('account_login')
            ->get();

        $themes = $query->withCount([
            'installations',
            'accessEntries',
            'installations as outdated_installs_count' => static fn (Builder $installations) => $installations->behindCatalog(),
        ])->paginate(25)->withQueryString();
        $activeFilters = $this->activeListFilters($search, $visibility);

        return ListFragment::respond($request, 'ops.themes.index', 'ops.themes._region', [
            'themes' => $themes,
            'search' => $search,
            'visibility' => $visibility,
            'visibilities' => ThemeVisibility::cases(),
            'filtersActive' => $activeFilters !== [],
            'activeFilters' => $activeFilters,
            'totalThemes' => $themes->total() > 0 ? $themes->total() : Theme::query()->count(),
            // The tiles sit outside the swapped region; a keystroke must not recount them.
            'summary' => ListFragment::wanted($request) ? null : $this->catalogSummary(),
            'connections' => $connections,
            'hasGithubApp' => GithubSetting::current()->hasManifestApp(),
            'appUrlIsPublic' => PublicAppUrl::isPublic(),
            'accountTypes' => ThemeGitAccountType::cases(),
            'selectionModes' => ThemeGitSelectionMode::cases(),
            'canSync' => $request->user()?->can('sync', Theme::class) ?? false,
            'canWriteGit' => $request->user()?->can('create', ThemeGitConnection::class) ?? false,
        ]);
    }

    /**
     * Catalog-wide counts behind the Themes tiles: a fixed handful of queries,
     * independent of the page size.
     *
     * @return array{total: int, visibility: array<string, int>, installs: int, outdated: int}
     */
    private function catalogSummary(): array
    {
        $byVisibility = Theme::query()
            ->selectRaw('visibility, COUNT(*) as aggregate')
            ->groupBy('visibility')
            ->pluck('aggregate', 'visibility');

        $visibility = [];
        foreach (ThemeVisibility::values() as $value) {
            $visibility[$value] = (int) ($byVisibility[$value] ?? 0);
        }

        return [
            'total' => array_sum($visibility),
            'visibility' => $visibility,
            'installs' => SiteThemeInstallation::query()->where('is_active', true)->count(),
            'outdated' => Theme::query()
                ->whereHas('installations', static fn (Builder $installations) => $installations->behindCatalog())
                ->count(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, value: string, url: string}>
     */
    private function activeListFilters(string $search, string $visibility): array
    {
        $applied = array_filter([
            'q' => $search,
            'visibility' => $visibility,
        ], static fn (string $value): bool => $value !== '');

        $labels = [
            'q' => __('themes.filter_search'),
            'visibility' => __('themes.filter_visibility'),
        ];
        $displayed = [
            'q' => $search,
            'visibility' => $visibility === '' ? '' : ThemeVisibility::from($visibility)->label(),
        ];

        $chips = [];
        foreach ($applied as $key => $value) {
            $chips[] = [
                'key' => $key,
                'label' => $labels[$key],
                'value' => $displayed[$key],
                'url' => route('ops.themes', array_diff_key($applied, [$key => null])),
            ];
        }

        return $chips;
    }

    public function show(Request $request, Theme $theme): View
    {
        $this->authorize('view', $theme);

        $theme->load([
            'allowedSites' => fn ($query) => $query->orderBy('name'),
            'installations.site',
        ]);

        return view('ops.themes.show', [
            'theme' => $theme,
            'visibilities' => ThemeVisibility::cases(),
            'sites' => Site::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'canWrite' => $request->user()?->can('update', $theme) ?? false,
        ]);
    }

    public function sync(Request $request, ThemeCatalogSync $sync): RedirectResponse|JsonResponse
    {
        $this->authorize('sync', Theme::class);

        if ($request->expectsJson()) {
            return $this->queueOpsJob($request, 'themes.catalog_sync', __('ops.jobs.catalog_sync'));
        }

        try {
            $result = $sync->sync();
        } catch (GitHubCredentialsException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (GitHubApiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'status',
            __('themes.flash.sync', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'deleted' => $result['deleted'],
            ]),
        );
    }

    public function update(Request $request, Theme $theme): RedirectResponse
    {
        $this->authorize('update', $theme);

        $validated = $request->validate([
            'visibility' => ['required', 'in:'.implode(',', ThemeVisibility::values())],
            'default_ref' => ['nullable', 'string', 'max:120'],
            // Only the CI gate form posts it; the other theme forms leave it alone.
            'ci_gate' => ['sometimes', 'boolean'],
        ]);

        $before = [
            'visibility' => $theme->visibility?->value,
            'default_ref' => $theme->default_ref,
        ];

        $theme->visibility = ThemeVisibility::from($validated['visibility']);
        $ref = trim((string) ($validated['default_ref'] ?? ''));
        if ($ref !== '') {
            $theme->default_ref = $ref;
        }
        $ciGateBefore = (bool) $theme->ci_gate;
        if (array_key_exists('ci_gate', $validated)) {
            $theme->ci_gate = (bool) $validated['ci_gate'];
        }
        $theme->save();

        if ($ciGateBefore !== (bool) $theme->ci_gate) {
            $theme->auditLogs()->create([
                'actor_user_id' => $request->user()?->id,
                'action' => 'theme.ci_gate_updated',
                'before' => ['ci_gate' => $ciGateBefore],
                'after' => ['ci_gate' => (bool) $theme->ci_gate],
                'ip' => $request->ip(),
            ]);

            return back()->with('status', $theme->ci_gate ? __('rollouts.theme.on') : __('rollouts.theme.off'));
        }

        $theme->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'theme.updated',
            'before' => $before,
            'after' => [
                'visibility' => $theme->visibility->value,
                'default_ref' => $theme->default_ref,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', 'Theme visibility saved. ZIP upload is not available in Plane.');
    }

    public function grantAccess(Request $request, Theme $theme): RedirectResponse
    {
        $this->authorize('update', $theme);

        $validated = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
        ]);

        $theme->allowedSites()->syncWithoutDetaching([$validated['site_id']]);

        $theme->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'theme.access_granted',
            'after' => [
                'theme_id' => $theme->theme_id,
                'site_id' => $validated['site_id'],
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', 'Site added to the theme allowlist.');
    }

    public function revokeAccess(Request $request, Theme $theme, Site $site): RedirectResponse
    {
        $this->authorize('update', $theme);

        $theme->allowedSites()->detach($site->id);

        $theme->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'theme.access_revoked',
            'after' => [
                'theme_id' => $theme->theme_id,
                'site_id' => $site->id,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', 'Site removed from the theme allowlist.');
    }
}
