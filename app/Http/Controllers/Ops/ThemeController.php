<?php

namespace App\Http\Controllers\Ops;

use App\Enums\ThemeGitAccountType;
use App\Enums\ThemeGitSelectionMode;
use App\Enums\ThemeVisibility;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\QueuesOpsJob;
use App\Models\GithubSetting;
use App\Models\Site;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubCredentialsException;
use App\Services\Themes\ThemeCatalogSync;
use App\Support\Lists\ListFragment;
use App\Support\PublicAppUrl;
use Illuminate\Contracts\View\View;
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

        $query = Theme::query()->orderBy('theme_id');

        if ($search !== '') {
            $term = addcslashes($search, '%_\\');
            $query->where(function ($builder) use ($term): void {
                $builder->where('theme_id', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('repo_full_name', 'like', "%{$term}%");
            });
        }

        if ($visibility !== '') {
            $query->where('visibility', $visibility);
        }

        $connections = ThemeGitConnection::query()
            ->withCount([
                'repos',
                'themes',
                'repos as included_repos_count' => static fn ($builder) => $builder->where('included', true),
            ])
            ->orderBy('account_login')
            ->get();

        return ListFragment::respond($request, 'ops.themes.index', 'ops.themes._region', [
            'themes' => $query->withCount(['installations', 'accessEntries'])->paginate(25)->withQueryString(),
            'search' => $search,
            'visibility' => $visibility,
            'visibilities' => ThemeVisibility::cases(),
            'filtersActive' => $search !== '' || $visibility !== '',
            'connections' => $connections,
            'hasGithubApp' => GithubSetting::current()->hasManifestApp(),
            'appUrlIsPublic' => PublicAppUrl::isPublic(),
            'accountTypes' => ThemeGitAccountType::cases(),
            'selectionModes' => ThemeGitSelectionMode::cases(),
            'canSync' => $request->user()?->can('sync', Theme::class) ?? false,
            'canWriteGit' => $request->user()?->can('create', ThemeGitConnection::class) ?? false,
        ]);
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
            ]),
        );
    }

    public function update(Request $request, Theme $theme): RedirectResponse
    {
        $this->authorize('update', $theme);

        $validated = $request->validate([
            'visibility' => ['required', 'in:'.implode(',', ThemeVisibility::values())],
            'default_ref' => ['nullable', 'string', 'max:120'],
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
        $theme->save();

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
