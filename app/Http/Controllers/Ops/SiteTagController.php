<?php

namespace App\Http\Controllers\Ops;

use App\Enums\SiteImportance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\BulkSiteIdsRequest;
use App\Http\Requests\Ops\BulkSiteImportanceRequest;
use App\Http\Requests\Ops\BulkSiteTagRequest;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteTag;
use App\Support\Lists\SiteSavedViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Tags and importance: how an operator organises the fleet. Everything here is
 * Plane metadata, so nothing contacts Coolify or a site and nothing is queued.
 * The Sites bulk bar posts here and stays on the list with its selection.
 */
class SiteTagController extends Controller
{
    public function bulk(BulkSiteTagRequest $request): JsonResponse|RedirectResponse
    {
        $sites = $this->sitesFromBulk($request);
        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return $this->answer($request, false, __('site_ops.bulk.empty'));
        }

        $operation = (string) $request->validated('tag_op');
        $ids = $sites->pluck('id')->all();

        if ($operation === 'create') {
            $name = trim((string) $request->validated('new_tag_name'));
            if ($name === '') {
                return $this->answer($request, false, __('sites.tags.flash.name_required'));
            }

            $tag = SiteTag::findByName($name);
            if ($tag === null) {
                if (SiteTag::query()->count() >= SiteTag::MAX) {
                    return $this->answer($request, false, __('sites.tags.flash.limit', ['max' => SiteTag::MAX]));
                }

                $tag = SiteTag::query()->create([
                    'name' => $name,
                    'color' => (string) ($request->validated('new_tag_color') ?: SiteTag::DEFAULT_COLOR),
                ]);
                $this->audit($request, 'site_tag.created', $tag, null, ['name' => $tag->name, 'color' => $tag->color]);
            }

            $attach = true;
        } else {
            [$verb, $tagId] = explode(':', $operation, 2);
            $tag = SiteTag::query()->find($tagId);
            if ($tag === null) {
                return $this->answer($request, false, __('sites.tags.flash.missing'));
            }

            $attach = $verb === 'attach';
        }

        if ($attach) {
            $tag->sites()->syncWithoutDetaching($ids);
        } else {
            $tag->sites()->detach($ids);
        }

        $this->audit($request, $attach ? 'site_tag.attached' : 'site_tag.detached', $tag, null, [
            'name' => $tag->name,
            'site_count' => count($ids),
            'site_ids' => array_slice($ids, 0, 50),
        ]);

        return $this->answer($request, true, __($attach ? 'sites.tags.flash.attached' : 'sites.tags.flash.detached', [
            'tag' => $tag->name,
            'count' => count($ids),
        ]));
    }

    public function bulkImportance(BulkSiteImportanceRequest $request): JsonResponse|RedirectResponse
    {
        $sites = $this->sitesFromBulk($request);
        foreach ($sites as $site) {
            $this->authorize('update', $site);
        }

        if ($sites->isEmpty()) {
            return $this->answer($request, false, __('site_ops.bulk.empty'));
        }

        $importance = SiteImportance::fromKey((string) $request->validated('importance')) ?? SiteImportance::Normal;
        $changed = $sites->filter(fn (Site $site): bool => $site->importanceLevel() !== $importance)->values();

        if ($changed->isNotEmpty()) {
            // toBase(): a label change is not a site change, so `updated_at` stays put.
            Site::query()
                ->whereIn('id', $changed->pluck('id')->all())
                ->toBase()
                ->update(['importance' => $importance->value]);

            foreach ($changed as $site) {
                $this->audit(
                    $request,
                    'site.importance_changed',
                    $site,
                    ['importance' => $site->importanceLevel()->key()],
                    ['importance' => $importance->key()],
                );
            }
        }

        return $this->answer($request, true, __('sites.importance.flash.updated', [
            'level' => $importance->label(),
            'count' => $sites->count(),
        ]));
    }

    /**
     * Rename / recolour. The bulk bar posts its whole form, so the new values
     * arrive keyed by tag id next to every other tag's inputs.
     */
    public function update(Request $request, SiteTag $tag): JsonResponse|RedirectResponse
    {
        $this->authorizeWrite($request);

        $name = trim((string) $request->input('tag_names.'.$tag->id, $tag->name));
        $color = (string) $request->input('tag_colors.'.$tag->id, $tag->color);

        if ($name === '' || mb_strlen($name) > SiteTag::NAME_MAX) {
            return $this->answer($request, false, __('sites.tags.flash.name_required'));
        }
        if (! in_array($color, SiteTag::COLORS, true)) {
            $color = $tag->colorKey();
        }
        if (SiteTag::findByName($name, $tag->id) !== null) {
            return $this->answer($request, false, __('sites.tags.flash.duplicate', ['tag' => $name]));
        }

        $before = ['name' => $tag->name, 'color' => $tag->color];
        $tag->update(['name' => $name, 'color' => $color]);
        $this->audit($request, 'site_tag.updated', $tag, $before, ['name' => $name, 'color' => $color]);

        return $this->answer($request, true, __('sites.tags.flash.updated', ['tag' => $name]));
    }

    public function destroy(Request $request, SiteTag $tag): JsonResponse|RedirectResponse
    {
        $this->authorizeWrite($request);

        $before = ['name' => $tag->name, 'color' => $tag->color, 'site_count' => $tag->sites()->count()];
        $this->audit($request, 'site_tag.deleted', $tag, $before, null);
        $tag->sites()->detach();
        $tag->delete();

        return $this->answer($request, true, __('sites.tags.flash.deleted', ['tag' => $before['name']]));
    }

    private function authorizeWrite(Request $request): void
    {
        abort_unless($request->user()?->canWriteOps() ?? false, 403);
    }

    /**
     * `keep_selection` tells the list to re-tick the same rows once the region
     * is re-rendered, so tagging can go on without selecting again.
     */
    private function answer(Request $request, bool $ok, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
                'refresh_list' => $ok,
                'keep_selection' => $ok,
            ], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'status' : 'error', $message);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Request $request, string $action, Site|SiteTag $subject, ?array $before, ?array $after): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * @return Collection<int, Site>
     */
    private function sitesFromBulk(BulkSiteIdsRequest $request): Collection
    {
        if ($request->boolean('all')) {
            return Site::query()
                ->matchingListFilters(...SiteSavedViews::bulkScopeArguments($request))
                ->orderBy('name')
                ->get();
        }

        return Site::query()
            ->whereIn('id', $request->validated('site_ids') ?? [])
            ->orderBy('name')
            ->get();
    }
}
